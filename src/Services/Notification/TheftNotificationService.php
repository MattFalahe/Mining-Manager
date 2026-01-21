<?php

namespace MiningManager\Services\Notification;

use MiningManager\Models\TheftIncident;
use Illuminate\Support\Facades\Log;

/**
 * Theft Notification Service
 *
 * Manages theft incident notifications via configured webhooks
 */
class TheftNotificationService
{
    /**
     * Webhook service instance
     *
     * @var WebhookService
     */
    protected $webhookService;

    /**
     * Constructor
     *
     * @param WebhookService $webhookService
     */
    public function __construct(WebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Send notification when a theft incident is detected
     *
     * @param TheftIncident $incident
     * @return bool
     */
    public function notifyTheftDetected(TheftIncident $incident): bool
    {
        Log::info('TheftNotificationService: Theft incident detected', [
            'incident_id' => $incident->id,
            'character_id' => $incident->character_id,
            'severity' => $incident->severity,
            'tax_owed' => $incident->tax_owed
        ]);

        $additionalData = [
            'incident_url' => route('mining-manager.theft.show', $incident->id),
        ];

        $results = $this->webhookService->sendTheftNotification($incident, 'theft_detected', $additionalData);

        // Return true if at least one webhook was successful
        foreach ($results as $result) {
            if ($result['success']) {
                return true;
            }
        }

        // If no webhooks configured or all failed, still return true (logged)
        return true;
    }

    /**
     * Send immediate alert for critical theft incidents
     *
     * @param TheftIncident $incident
     * @return bool
     */
    public function notifyCriticalTheft(TheftIncident $incident): bool
    {
        if ($incident->severity !== 'critical') {
            return false;
        }

        Log::warning('TheftNotificationService: CRITICAL theft incident detected', [
            'incident_id' => $incident->id,
            'character_id' => $incident->character_id,
            'character_name' => $incident->character_name,
            'tax_owed' => $incident->tax_owed,
            'ore_value' => $incident->ore_value
        ]);

        $additionalData = [
            'incident_url' => route('mining-manager.theft.show', $incident->id),
        ];

        $results = $this->webhookService->sendTheftNotification($incident, 'critical_theft', $additionalData);

        // Return true if at least one webhook was successful
        foreach ($results as $result) {
            if ($result['success']) {
                return true;
            }
        }

        return true;
    }

    /**
     * Send notification when an incident is resolved
     *
     * @param TheftIncident $incident
     * @return bool
     */
    public function notifyIncidentResolved(TheftIncident $incident): bool
    {
        Log::info('TheftNotificationService: Incident resolved', [
            'incident_id' => $incident->id,
            'character_id' => $incident->character_id,
            'resolution_status' => $incident->status,
            'resolved_by' => $incident->resolved_by
        ]);

        $additionalData = [
            'incident_url' => route('mining-manager.theft.show', $incident->id),
            'resolved_by' => $incident->resolved_by,
        ];

        $results = $this->webhookService->sendTheftNotification($incident, 'incident_resolved', $additionalData);

        // Return true if at least one webhook was successful
        foreach ($results as $result) {
            if ($result['success']) {
                return true;
            }
        }

        return true;
    }

    /**
     * Send bulk notification for multiple incidents
     *
     * Note: Currently sends individual notifications. Future enhancement could add digest format.
     *
     * @param \Illuminate\Database\Eloquent\Collection $incidents
     * @return bool
     */
    public function notifyBulkIncidents($incidents): bool
    {
        if ($incidents->isEmpty()) {
            return false;
        }

        Log::info('TheftNotificationService: Bulk incidents notification', [
            'count' => $incidents->count(),
            'total_value_at_risk' => $incidents->sum('tax_owed')
        ]);

        $successCount = 0;

        foreach ($incidents as $incident) {
            if ($this->notifyTheftDetected($incident)) {
                $successCount++;
            }
        }

        return $successCount > 0;
    }

    /**
     * Test webhook configuration
     *
     * @param \MiningManager\Models\WebhookConfiguration $webhook
     * @return array Test results
     */
    public function testWebhook($webhook): array
    {
        Log::info('TheftNotificationService: Testing webhook', [
            'webhook_id' => $webhook->id,
            'webhook_type' => $webhook->type
        ]);

        return $this->webhookService->testWebhook($webhook);
    }

    /**
     * Notify about active theft in progress
     *
     * PRIORITY: IMMEDIATE - Character continues mining with unpaid taxes
     *
     * @param TheftIncident $incident
     * @param float $newMiningValue - Value of new mining since incident created
     * @return void
     */
    public function notifyActiveTheft(TheftIncident $incident, float $newMiningValue): void
    {
        Log::warning('ACTIVE THEFT IN PROGRESS', [
            'character_id' => $incident->character_id,
            'character_name' => $incident->character_name,
            'original_value' => $incident->ore_value - $newMiningValue,
            'new_value' => $newMiningValue,
            'total_value' => $incident->ore_value,
            'activity_count' => $incident->activity_count,
            'incident_id' => $incident->id,
            'last_activity' => $incident->last_activity_at->toDateTimeString(),
        ]);

        $additionalData = [
            'incident_url' => route('mining-manager.theft.show', $incident->id),
            'new_mining_value' => $newMiningValue,
            'last_activity' => $incident->last_activity_at->format('Y-m-d H:i:s'),
        ];

        $this->webhookService->sendTheftNotification($incident, 'active_theft', $additionalData);
    }

    /**
     * Get notification statistics from all webhooks
     *
     * @return array
     */
    public function getNotificationStatistics(): array
    {
        $webhooks = \MiningManager\Models\WebhookConfiguration::all();

        $totalSent = $webhooks->sum('success_count');
        $totalFailed = $webhooks->sum('failure_count');
        $total = $totalSent + $totalFailed;

        $deliveryRate = $total > 0 ? round(($totalSent / $total) * 100, 1) : 0;

        $lastNotification = $webhooks
            ->filter(fn($w) => $w->last_success_at !== null)
            ->sortByDesc('last_success_at')
            ->first();

        return [
            'total_sent' => $totalSent,
            'total_failed' => $totalFailed,
            'delivery_rate' => $deliveryRate,
            'last_notification' => $lastNotification ? $lastNotification->last_success_at->toDateTimeString() : null,
            'webhooks_configured' => $webhooks->count(),
            'webhooks_enabled' => $webhooks->where('is_enabled', true)->count(),
        ];
    }
}
