<?php

namespace MiningManager\Services\Notification;

use MiningManager\Models\TheftIncident;
use MiningManager\Models\WebhookConfiguration;
use Illuminate\Support\Facades\Log;

/**
 * Theft Notification Service
 *
 * Thin wrapper around NotificationService for theft-specific notifications.
 * Preserves the convenience API (notifyTheftDetected, notifyActiveTheft, etc.)
 * used by the theft detection commands while routing the actual dispatch
 * through the consolidated NotificationService (Phase D of the notification
 * consolidation, 2026-04-23).
 *
 * Kept as a separate class because:
 *  - Callers (DetectMoonTheftCommand, MonitorActiveTheftsCommand) have a
 *    stable API here that includes theft-specific logging (severity, character,
 *    tax_owed) that's useful to keep close to the dispatch call.
 *  - TheftIncidentController will wire notifyIncidentResolved in via this
 *    class, giving a single place where theft-related notification decisions
 *    (which subtype to fire, which fields to log) live.
 */
class TheftNotificationService
{
    /**
     * Notification service instance — the consolidated dispatcher.
     */
    protected NotificationService $notificationService;

    /**
     * Constructor
     *
     * @param NotificationService $notificationService
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Send notification when a theft incident is detected.
     *
     * @param TheftIncident $incident
     * @return bool Always true (non-failure path; send() errors are logged).
     */
    public function notifyTheftDetected(TheftIncident $incident): bool
    {
        Log::info('TheftNotificationService: Theft incident detected', [
            'incident_id' => $incident->id,
            'character_id' => $incident->character_id,
            'severity' => $incident->severity,
            'tax_owed' => $incident->tax_owed,
        ]);

        $this->notificationService->sendTheftDetected($incident, [
            'incident_url' => route('mining-manager.theft.show', $incident->id),
        ]);

        return true;
    }

    /**
     * Send immediate alert for critical theft incidents.
     *
     * @param TheftIncident $incident
     * @return bool Returns false when the incident is not critical severity.
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
            'ore_value' => $incident->ore_value,
        ]);

        $this->notificationService->sendCriticalTheft($incident, [
            'incident_url' => route('mining-manager.theft.show', $incident->id),
        ]);

        return true;
    }

    /**
     * Send notification when a theft incident is resolved (director action).
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
            'resolved_by' => $incident->resolved_by,
        ]);

        $this->notificationService->sendIncidentResolved($incident, [
            'incident_url' => route('mining-manager.theft.show', $incident->id),
            'resolved_by' => $incident->resolved_by,
        ]);

        return true;
    }

    /**
     * Send bulk notification for multiple incidents.
     *
     * Currently emits one notification per incident — a real "digest" format
     * is still on the wish-list. Left as a placeholder so future callers
     * don't have to reimplement the loop.
     *
     * @param \Illuminate\Database\Eloquent\Collection $incidents
     * @return bool true if at least one dispatch returned success
     */
    public function notifyBulkIncidents($incidents): bool
    {
        if ($incidents->isEmpty()) {
            return false;
        }

        Log::info('TheftNotificationService: Bulk incidents notification', [
            'count' => $incidents->count(),
            'total_value_at_risk' => $incidents->sum('tax_owed'),
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
     * Test webhook configuration — delegates to NotificationService.
     *
     * @param WebhookConfiguration $webhook
     * @return array ['success' => bool, 'error' => string?]
     */
    public function testWebhook($webhook): array
    {
        Log::info('TheftNotificationService: Testing webhook', [
            'webhook_id' => $webhook->id,
            'webhook_type' => $webhook->type,
        ]);

        return $this->notificationService->testWebhook($webhook);
    }

    /**
     * Notify about active theft in progress.
     *
     * PRIORITY: IMMEDIATE — character continues mining with unpaid taxes.
     *
     * @param TheftIncident $incident
     * @param float $newMiningValue Value of new mining since incident created
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
            'last_activity' => $incident->last_activity_at ? $incident->last_activity_at->toDateTimeString() : 'N/A',
        ]);

        $this->notificationService->sendActiveTheft($incident, [
            'incident_url' => route('mining-manager.theft.show', $incident->id),
            'new_mining_value' => $newMiningValue,
            'last_activity' => $incident->last_activity_at ? $incident->last_activity_at->format('Y-m-d H:i:s') : 'N/A',
        ]);
    }

    /**
     * Get notification statistics from all webhooks.
     *
     * @return array
     */
    public function getNotificationStatistics(): array
    {
        $webhooks = WebhookConfiguration::all();

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
            'last_notification_at' => $lastNotification?->last_success_at,
            'webhook_count' => $webhooks->count(),
            'enabled_count' => $webhooks->where('is_enabled', true)->count(),
        ];
    }
}
