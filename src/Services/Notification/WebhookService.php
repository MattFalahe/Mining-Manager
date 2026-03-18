<?php

namespace MiningManager\Services\Notification;

use MiningManager\Models\WebhookConfiguration;
use MiningManager\Models\TheftIncident;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MiningReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Configuration\SettingsManagerService;
use Carbon\Carbon;

/**
 * Webhook Service
 *
 * Handles sending notifications to configured webhooks (Discord, Slack, Custom)
 */
class WebhookService
{
    /**
     * Send a theft detection notification to all configured webhooks
     *
     * @param TheftIncident $incident
     * @param string $eventType (theft_detected, critical_theft, active_theft, incident_resolved)
     * @param array $additionalData
     * @return array Results for each webhook
     */
    public function sendTheftNotification(TheftIncident $incident, string $eventType, array $additionalData = []): array
    {
        // Get enabled webhooks for this event type
        $webhooks = WebhookConfiguration::enabled()
            ->forEvent($eventType)
            ->forCorporation($incident->corporation_id)
            ->get();

        if ($webhooks->isEmpty()) {
            Log::debug("WebhookService: No webhooks configured for event type: {$eventType}");
            return [];
        }

        $results = [];

        foreach ($webhooks as $webhook) {
            try {
                $result = $this->sendToWebhook($webhook, $incident, $eventType, $additionalData);
                $results[$webhook->id] = $result;

                if ($result['success']) {
                    $webhook->recordSuccess();
                } else {
                    $webhook->recordFailure($result['error'] ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                Log::error("WebhookService: Exception sending to webhook {$webhook->id}", [
                    'error' => $e->getMessage(),
                    'webhook_id' => $webhook->id,
                ]);

                $webhook->recordFailure($e->getMessage());
                $results[$webhook->id] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Send notification to a specific webhook
     *
     * @param WebhookConfiguration $webhook
     * @param TheftIncident $incident
     * @param string $eventType
     * @param array $additionalData
     * @return array
     */
    protected function sendToWebhook(WebhookConfiguration $webhook, TheftIncident $incident, string $eventType, array $additionalData): array
    {
        switch ($webhook->type) {
            case 'discord':
                return $this->sendToDiscord($webhook, $incident, $eventType, $additionalData);

            case 'slack':
                return $this->sendToSlack($webhook, $incident, $eventType, $additionalData);

            case 'custom':
                return $this->sendToCustomWebhook($webhook, $incident, $eventType, $additionalData);

            default:
                return [
                    'success' => false,
                    'error' => "Unknown webhook type: {$webhook->type}",
                ];
        }
    }

    /**
     * Send notification to Discord webhook
     *
     * @param WebhookConfiguration $webhook
     * @param TheftIncident $incident
     * @param string $eventType
     * @param array $additionalData
     * @return array
     */
    protected function sendToDiscord(WebhookConfiguration $webhook, TheftIncident $incident, string $eventType, array $additionalData): array
    {
        $embed = $this->buildDiscordEmbed($incident, $eventType, $additionalData);

        $payload = [
            'embeds' => [$embed],
        ];

        // Add role mention if configured
        if ($webhook->discord_role_id) {
            $payload['content'] = $webhook->getDiscordRoleMention();
        }

        // Add custom username and avatar if configured
        if ($webhook->discord_username) {
            $payload['username'] = $webhook->discord_username;
        }

        if ($webhook->discord_avatar_url) {
            $payload['avatar_url'] = $webhook->discord_avatar_url;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);

            if ($response->successful() || $response->status() === 204) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => "Discord webhook returned status {$response->status()}: {$response->body()}",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to send to Discord: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Build Discord embed for theft incident
     *
     * @param TheftIncident $incident
     * @param string $eventType
     * @param array $additionalData
     * @return array
     */
    protected function buildDiscordEmbed(TheftIncident $incident, string $eventType, array $additionalData): array
    {
        $color = $this->getColorForEventType($eventType);
        $title = $this->getTitleForEventType($eventType);

        $embed = [
            'title' => $title,
            'color' => $color,
            'timestamp' => now()->toIso8601String(),
            'fields' => [],
            'footer' => [
                'text' => $this->getCorpName() . ' Mining Manager',
            ],
        ];

        // Add character info
        $embed['fields'][] = [
            'name' => '👤 Character',
            'value' => $incident->character_name ?? "Character ID: {$incident->character_id}",
            'inline' => true,
        ];

        // Add severity
        $severityEmoji = $this->getSeverityEmoji($incident->severity);
        $embed['fields'][] = [
            'name' => '⚠️ Severity',
            'value' => "{$severityEmoji} " . ucfirst($incident->severity),
            'inline' => true,
        ];

        // Add ore value
        $embed['fields'][] = [
            'name' => '💰 Ore Value',
            'value' => number_format($incident->ore_value, 0) . ' ISK',
            'inline' => true,
        ];

        // Add tax owed
        $embed['fields'][] = [
            'name' => '📋 Tax Owed',
            'value' => number_format($incident->tax_owed, 0) . ' ISK',
            'inline' => true,
        ];

        // Add detection date
        $embed['fields'][] = [
            'name' => '📅 Detected',
            'value' => $incident->incident_date ? $incident->incident_date->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s'),
            'inline' => true,
        ];

        // Add status
        $statusEmoji = $this->getStatusEmoji($incident->status);
        $embed['fields'][] = [
            'name' => '🔍 Status',
            'value' => "{$statusEmoji} " . ucfirst($incident->status),
            'inline' => true,
        ];

        // Add active theft info if applicable
        if ($eventType === 'active_theft' && isset($additionalData['new_mining_value'])) {
            $embed['fields'][] = [
                'name' => '🔥 Active Theft Alert',
                'value' => "Character continues mining!\nNew value: " . number_format($additionalData['new_mining_value'], 0) . ' ISK' .
                          "\nActivity count: " . ($incident->activity_count ?? 1),
                'inline' => false,
            ];

            if (isset($additionalData['last_activity'])) {
                $embed['fields'][] = [
                    'name' => '🕐 Last Activity',
                    'value' => $additionalData['last_activity'],
                    'inline' => false,
                ];
            }
        }

        // Add incident link if available
        if (isset($additionalData['incident_url'])) {
            $embed['fields'][] = [
                'name' => '🔗 View Incident',
                'value' => "[Click here to view details]({$additionalData['incident_url']})",
                'inline' => false,
            ];
        }

        return $embed;
    }

    /**
     * Send notification to Slack webhook
     *
     * @param WebhookConfiguration $webhook
     * @param TheftIncident $incident
     * @param string $eventType
     * @param array $additionalData
     * @return array
     */
    protected function sendToSlack(WebhookConfiguration $webhook, TheftIncident $incident, string $eventType, array $additionalData): array
    {
        $payload = [
            'text' => $this->getTitleForEventType($eventType),
            'blocks' => $this->buildSlackBlocks($incident, $eventType, $additionalData),
        ];

        // Add channel if configured
        if ($webhook->slack_channel) {
            $payload['channel'] = $webhook->slack_channel;
        }

        // Add username if configured
        if ($webhook->slack_username) {
            $payload['username'] = $webhook->slack_username;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);

            if ($response->successful()) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => "Slack webhook returned status {$response->status()}: {$response->body()}",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to send to Slack: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Build Slack blocks for theft incident
     *
     * @param TheftIncident $incident
     * @param string $eventType
     * @param array $additionalData
     * @return array
     */
    protected function buildSlackBlocks(TheftIncident $incident, string $eventType, array $additionalData): array
    {
        $blocks = [];

        // Header
        $blocks[] = [
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => $this->getTitleForEventType($eventType),
            ],
        ];

        // Character and severity info
        $fields = [
            [
                'type' => 'mrkdwn',
                'text' => "*Character:*\n" . ($incident->character_name ?? "ID: {$incident->character_id}"),
            ],
            [
                'type' => 'mrkdwn',
                'text' => "*Severity:*\n" . ucfirst($incident->severity),
            ],
            [
                'type' => 'mrkdwn',
                'text' => "*Ore Value:*\n" . number_format($incident->ore_value, 0) . ' ISK',
            ],
            [
                'type' => 'mrkdwn',
                'text' => "*Tax Owed:*\n" . number_format($incident->tax_owed, 0) . ' ISK',
            ],
        ];

        $blocks[] = [
            'type' => 'section',
            'fields' => $fields,
        ];

        // Active theft warning if applicable
        if ($eventType === 'active_theft' && isset($additionalData['new_mining_value'])) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => ":fire: *Active Theft Alert*\nCharacter continues mining!\nNew value: " .
                             number_format($additionalData['new_mining_value'], 0) . ' ISK',
                ],
            ];
        }

        // Link button if available
        if (isset($additionalData['incident_url'])) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'View Incident Details',
                        ],
                        'url' => $additionalData['incident_url'],
                    ],
                ],
            ];
        }

        return $blocks;
    }

    /**
     * Send notification to custom webhook
     *
     * @param WebhookConfiguration $webhook
     * @param TheftIncident $incident
     * @param string $eventType
     * @param array $additionalData
     * @return array
     */
    protected function sendToCustomWebhook(WebhookConfiguration $webhook, TheftIncident $incident, string $eventType, array $additionalData): array
    {
        // Build payload from template or use default structure
        if ($webhook->custom_payload_template) {
            $payload = $this->processCustomTemplate($webhook->custom_payload_template, $incident, $eventType, $additionalData);
        } else {
            $payload = $this->buildDefaultCustomPayload($incident, $eventType, $additionalData);
        }

        // Prepare HTTP request
        $request = Http::timeout(10);

        // Add custom headers if configured
        if ($webhook->custom_headers && is_array($webhook->custom_headers)) {
            foreach ($webhook->custom_headers as $key => $value) {
                $request = $request->withHeader($key, $value);
            }
        }

        try {
            $response = $request->post($webhook->webhook_url, $payload);

            if ($response->successful()) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => "Custom webhook returned status {$response->status()}: {$response->body()}",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to send to custom webhook: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Process custom payload template with incident data
     *
     * @param string $template
     * @param TheftIncident $incident
     * @param string $eventType
     * @param array $additionalData
     * @return array
     */
    protected function processCustomTemplate(string $template, TheftIncident $incident, string $eventType, array $additionalData): array
    {
        $data = array_merge([
            'character_id' => $incident->character_id,
            'character_name' => $incident->character_name,
            'severity' => $incident->severity,
            'ore_value' => $incident->ore_value,
            'tax_owed' => $incident->tax_owed,
            'status' => $incident->status,
            'detected_at' => $incident->incident_date->toIso8601String(),
            'event_type' => $eventType,
        ], $additionalData);

        // Replace template variables
        $processed = $template;
        foreach ($data as $key => $value) {
            $processed = str_replace("{{" . $key . "}}", $value, $processed);
        }

        return json_decode($processed, true) ?? [];
    }

    /**
     * Build default payload structure for custom webhooks
     *
     * @param TheftIncident $incident
     * @param string $eventType
     * @param array $additionalData
     * @return array
     */
    protected function buildDefaultCustomPayload(TheftIncident $incident, string $eventType, array $additionalData): array
    {
        return array_merge([
            'event_type' => $eventType,
            'incident' => [
                'id' => $incident->id,
                'character_id' => $incident->character_id,
                'character_name' => $incident->character_name,
                'severity' => $incident->severity,
                'ore_value' => $incident->ore_value,
                'tax_owed' => $incident->tax_owed,
                'status' => $incident->status,
                'detected_at' => $incident->incident_date ? $incident->incident_date->toIso8601String() : now()->toIso8601String(),
            ],
            'timestamp' => now()->toIso8601String(),
        ], $additionalData);
    }

    /**
     * Test a webhook configuration
     *
     * @param WebhookConfiguration $webhook
     * @return array
     */
    public function testWebhook(WebhookConfiguration $webhook): array
    {
        // Create a dummy incident for testing
        $testIncident = new TheftIncident([
            'character_id' => 123456789,
            'character_name' => 'Test Character',
            'severity' => 'medium',
            'ore_value' => 50000000,
            'tax_owed' => 5000000,
            'status' => 'detected',
            'incident_date' => now(),
        ]);

        $additionalData = [
            'test_mode' => true,
            'incident_url' => route('mining-manager.theft.index'),
        ];

        try {
            return $this->sendToWebhook($webhook, $testIncident, 'theft_detected', $additionalData);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ============================================================================
    // MOON EVENT NOTIFICATIONS
    // ============================================================================

    /**
     * Send a moon-related notification to all configured webhooks
     *
     * @param string $eventType (moon_arrival, jackpot_detected)
     * @param array $data Moon event data
     * @param int|null $corporationId
     * @return array Results for each webhook
     */
    public function sendMoonNotification(string $eventType, array $data, ?int $corporationId = null): array
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forEvent($eventType)
            ->get()
            ->filter(function ($webhook) use ($corporationId) {
                // Global webhooks (null corp) always match, corp-specific only match their corp
                return $webhook->corporation_id === null || $webhook->corporation_id == $corporationId;
            });

        if ($webhooks->isEmpty()) {
            Log::debug("WebhookService: No webhooks configured for moon event: {$eventType}");
            return [];
        }

        $results = [];

        foreach ($webhooks as $webhook) {
            try {
                $result = $this->sendMoonToWebhook($webhook, $eventType, $data);
                $results[$webhook->id] = $result;

                if ($result['success']) {
                    $webhook->recordSuccess();
                } else {
                    $webhook->recordFailure($result['error'] ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                Log::error("WebhookService: Exception sending moon notification to webhook {$webhook->id}", [
                    'error' => $e->getMessage(),
                    'event_type' => $eventType,
                ]);

                $webhook->recordFailure($e->getMessage());
                $results[$webhook->id] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Send moon notification to a specific webhook
     *
     * @param WebhookConfiguration $webhook
     * @param string $eventType
     * @param array $data
     * @return array
     */
    protected function sendMoonToWebhook(WebhookConfiguration $webhook, string $eventType, array $data): array
    {
        switch ($webhook->type) {
            case 'discord':
                return $this->sendMoonToDiscord($webhook, $eventType, $data);

            case 'slack':
                return $this->sendMoonToSlack($webhook, $eventType, $data);

            case 'custom':
                return $this->sendMoonToCustom($webhook, $eventType, $data);

            default:
                return ['success' => false, 'error' => "Unknown webhook type: {$webhook->type}"];
        }
    }

    /**
     * Send moon notification to Discord
     */
    protected function sendMoonToDiscord(WebhookConfiguration $webhook, string $eventType, array $data): array
    {
        $embed = $this->buildMoonDiscordEmbed($eventType, $data);

        $payload = ['embeds' => [$embed]];

        if ($webhook->discord_role_id) {
            $payload['content'] = $webhook->getDiscordRoleMention();
        }

        if ($webhook->discord_username) {
            $payload['username'] = $webhook->discord_username;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);

            if ($response->successful() || $response->status() === 204) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => "Discord returned status {$response->status()}: {$response->body()}",
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to Discord: {$e->getMessage()}"];
        }
    }

    /**
     * Build Discord embed for moon events
     */
    protected function buildMoonDiscordEmbed(string $eventType, array $data): array
    {
        $color = $this->getColorForEventType($eventType);
        $title = $this->getTitleForEventType($eventType);

        $embed = [
            'title' => $title,
            'color' => $color,
            'timestamp' => now()->toIso8601String(),
            'fields' => [],
            'footer' => ['text' => $this->getCorpName() . ' Mining Manager'],
        ];

        if ($eventType === 'jackpot_detected') {
            if (isset($data['moon_name'])) {
                $embed['fields'][] = [
                    'name' => 'Moon',
                    'value' => $data['moon_name'],
                    'inline' => true,
                ];
            }

            if (isset($data['system_name'])) {
                $embed['fields'][] = [
                    'name' => 'System',
                    'value' => $data['system_name'],
                    'inline' => true,
                ];
            }

            if (isset($data['structure_name'])) {
                $embed['fields'][] = [
                    'name' => 'Structure',
                    'value' => $data['structure_name'],
                    'inline' => true,
                ];
            }

            if (isset($data['detected_by'])) {
                $embed['fields'][] = [
                    'name' => 'Detected By',
                    'value' => $data['detected_by'],
                    'inline' => true,
                ];
            }

            if (isset($data['jackpot_ores']) && !empty($data['jackpot_ores'])) {
                $oreList = implode("\n", array_map(function ($ore) {
                    return "- {$ore['name']} (x" . number_format($ore['quantity']) . ")";
                }, $data['jackpot_ores']));

                $embed['fields'][] = [
                    'name' => 'Jackpot Ores Found',
                    'value' => $oreList,
                    'inline' => false,
                ];
            }

            if (isset($data['jackpot_percentage'])) {
                $embed['fields'][] = [
                    'name' => 'Jackpot %',
                    'value' => $data['jackpot_percentage'] . '% of ores are +100% variants',
                    'inline' => true,
                ];
            }

            $embed['description'] = 'A jackpot moon extraction has been confirmed! Miners found +100% variant ores in the belt.';

        } elseif ($eventType === 'moon_arrival') {
            if (isset($data['moon_name'])) {
                $embed['fields'][] = [
                    'name' => 'Moon',
                    'value' => $data['moon_name'],
                    'inline' => true,
                ];
            }

            if (isset($data['structure_name'])) {
                $embed['fields'][] = [
                    'name' => 'Structure',
                    'value' => $data['structure_name'],
                    'inline' => true,
                ];
            }

            if (isset($data['chunk_arrival_time'])) {
                $embed['fields'][] = [
                    'name' => 'Chunk Arrived',
                    'value' => $data['chunk_arrival_time'],
                    'inline' => true,
                ];
            }

            if (isset($data['natural_decay_time'])) {
                $embed['fields'][] = [
                    'name' => 'Decays At',
                    'value' => $data['natural_decay_time'],
                    'inline' => true,
                ];
            }

            if (isset($data['estimated_value']) && $data['estimated_value'] > 0) {
                $embed['fields'][] = [
                    'name' => 'Estimated Value',
                    'value' => number_format($data['estimated_value'], 0) . ' ISK',
                    'inline' => true,
                ];
            }

            if (isset($data['ore_summary']) && !empty($data['ore_summary'])) {
                $embed['fields'][] = [
                    'name' => 'Ore Composition',
                    'value' => $data['ore_summary'],
                    'inline' => false,
                ];
            }

            $embed['description'] = 'A moon chunk is ready for mining!';
        }

        return $embed;
    }

    /**
     * Send moon notification to Slack
     */
    protected function sendMoonToSlack(WebhookConfiguration $webhook, string $eventType, array $data): array
    {
        $title = $this->getTitleForEventType($eventType);

        $fields = [];

        if (isset($data['moon_name'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Moon:*\n{$data['moon_name']}"];
        }
        if (isset($data['structure_name'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Structure:*\n{$data['structure_name']}"];
        }

        if ($eventType === 'jackpot_detected') {
            if (isset($data['detected_by'])) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Detected By:*\n{$data['detected_by']}"];
            }
            if (isset($data['jackpot_percentage'])) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Jackpot:*\n{$data['jackpot_percentage']}% +100% ores"];
            }
        } elseif ($eventType === 'moon_arrival') {
            if (isset($data['chunk_arrival_time'])) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Arrived:*\n{$data['chunk_arrival_time']}"];
            }
            if (isset($data['estimated_value'])) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Value:*\n" . number_format($data['estimated_value'], 0) . ' ISK'];
            }
        }

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $title]],
            ['type' => 'section', 'fields' => $fields],
        ];

        $payload = ['text' => $title, 'blocks' => $blocks];

        if ($webhook->slack_channel) {
            $payload['channel'] = $webhook->slack_channel;
        }
        if ($webhook->slack_username) {
            $payload['username'] = $webhook->slack_username;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);
            if ($response->successful()) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => "Slack returned status {$response->status()}: {$response->body()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to Slack: {$e->getMessage()}"];
        }
    }

    /**
     * Send moon notification to custom webhook
     */
    protected function sendMoonToCustom(WebhookConfiguration $webhook, string $eventType, array $data): array
    {
        $payload = array_merge([
            'event_type' => $eventType,
            'timestamp' => now()->toIso8601String(),
        ], $data);

        $request = Http::timeout(10);

        if ($webhook->custom_headers && is_array($webhook->custom_headers)) {
            foreach ($webhook->custom_headers as $key => $value) {
                $request = $request->withHeader($key, $value);
            }
        }

        try {
            $response = $request->post($webhook->webhook_url, $payload);
            if ($response->successful()) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => "Custom webhook returned status {$response->status()}: {$response->body()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to custom webhook: {$e->getMessage()}"];
        }
    }

    // ============================================================================
    // MINING EVENT NOTIFICATIONS
    // ============================================================================

    /**
     * Send an event notification to all configured webhooks
     *
     * @param string $eventType (event_created, event_started, event_completed)
     * @param MiningEvent $event
     * @param array $additionalData
     * @return array Results for each webhook
     */
    public function sendEventNotification(string $eventType, MiningEvent $event, array $additionalData = []): array
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forEvent($eventType)
            ->get()
            ->filter(function ($webhook) use ($event) {
                return $webhook->corporation_id === null || $webhook->corporation_id == $event->corporation_id;
            });

        if ($webhooks->isEmpty()) {
            Log::debug("WebhookService: No webhooks configured for event type: {$eventType}");
            return [];
        }

        $results = [];

        foreach ($webhooks as $webhook) {
            try {
                $result = $this->sendEventToWebhook($webhook, $eventType, $event, $additionalData);
                $results[$webhook->id] = $result;

                if ($result['success']) {
                    $webhook->recordSuccess();
                } else {
                    $webhook->recordFailure($result['error'] ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                Log::error("WebhookService: Exception sending event notification to webhook {$webhook->id}", [
                    'error' => $e->getMessage(),
                    'event_type' => $eventType,
                    'mining_event_id' => $event->id,
                ]);

                $webhook->recordFailure($e->getMessage());
                $results[$webhook->id] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Send event notification to a specific webhook
     */
    protected function sendEventToWebhook(WebhookConfiguration $webhook, string $eventType, MiningEvent $event, array $additionalData): array
    {
        switch ($webhook->type) {
            case 'discord':
                return $this->sendEventToDiscord($webhook, $eventType, $event, $additionalData);

            case 'slack':
                return $this->sendEventToSlack($webhook, $eventType, $event, $additionalData);

            case 'custom':
                return $this->sendEventToCustom($webhook, $eventType, $event, $additionalData);

            default:
                return ['success' => false, 'error' => "Unknown webhook type: {$webhook->type}"];
        }
    }

    /**
     * Send event notification to Discord
     */
    protected function sendEventToDiscord(WebhookConfiguration $webhook, string $eventType, MiningEvent $event, array $additionalData): array
    {
        $embed = $this->buildEventDiscordEmbed($eventType, $event, $additionalData);

        $payload = ['embeds' => [$embed]];

        if ($webhook->discord_role_id) {
            $payload['content'] = $webhook->getDiscordRoleMention();
        }

        if ($webhook->discord_username) {
            $payload['username'] = $webhook->discord_username;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);

            if ($response->successful() || $response->status() === 204) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => "Discord returned status {$response->status()}: {$response->body()}",
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to Discord: {$e->getMessage()}"];
        }
    }

    /**
     * Build Discord embed for mining events
     */
    protected function buildEventDiscordEmbed(string $eventType, MiningEvent $event, array $additionalData): array
    {
        $color = $this->getColorForEventType($eventType);
        $title = $this->getTitleForEventType($eventType);

        $embed = [
            'title' => $title,
            'color' => $color,
            'timestamp' => now()->toIso8601String(),
            'fields' => [],
            'footer' => ['text' => $this->getCorpName() . ' Mining Manager'],
        ];

        // Event name
        $embed['fields'][] = [
            'name' => '📋 Event',
            'value' => $event->name,
            'inline' => false,
        ];

        // Event type
        $embed['fields'][] = [
            'name' => '🏷️ Type',
            'value' => $event->getTypeLabel(),
            'inline' => true,
        ];

        // Tax modifier
        $embed['fields'][] = [
            'name' => '💰 Tax Modifier',
            'value' => $event->getTaxModifierLabel(),
            'inline' => true,
        ];

        // Location
        $location = $event->getLocationName() ?? $event->getLocationScopeLabel();
        $embed['fields'][] = [
            'name' => '📍 Location',
            'value' => $location,
            'inline' => true,
        ];

        // Times based on event type
        if ($eventType === 'event_created' || $eventType === 'event_started') {
            $embed['fields'][] = [
                'name' => '🕐 Start',
                'value' => $event->start_time ? $event->start_time->format('Y-m-d H:i') : 'TBD',
                'inline' => true,
            ];

            if ($event->end_time) {
                $embed['fields'][] = [
                    'name' => '🕐 End',
                    'value' => $event->end_time->format('Y-m-d H:i'),
                    'inline' => true,
                ];
            }
        }

        // Completion stats
        if ($eventType === 'event_completed') {
            $embed['fields'][] = [
                'name' => '⛏️ Total Mined',
                'value' => number_format($event->total_mined ?? 0, 0) . ' ISK',
                'inline' => true,
            ];

            $embed['fields'][] = [
                'name' => '👥 Participants',
                'value' => (string)($event->participant_count ?? 0),
                'inline' => true,
            ];
        }

        // Description for different event types
        $embed['description'] = match($eventType) {
            'event_created' => 'A new mining event has been scheduled!',
            'event_started' => 'A mining event is now active! Join in and mine!',
            'event_completed' => 'The mining event has concluded. Thank you to all participants!',
            default => '',
        };

        return $embed;
    }

    /**
     * Send event notification to Slack
     */
    protected function sendEventToSlack(WebhookConfiguration $webhook, string $eventType, MiningEvent $event, array $additionalData): array
    {
        $title = $this->getTitleForEventType($eventType);

        $fields = [
            ['type' => 'mrkdwn', 'text' => "*Event:*\n{$event->name}"],
            ['type' => 'mrkdwn', 'text' => "*Type:*\n{$event->getTypeLabel()}"],
            ['type' => 'mrkdwn', 'text' => "*Tax Modifier:*\n{$event->getTaxModifierLabel()}"],
        ];

        $location = $event->getLocationName() ?? $event->getLocationScopeLabel();
        $fields[] = ['type' => 'mrkdwn', 'text' => "*Location:*\n{$location}"];

        if ($eventType === 'event_completed') {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Total Mined:*\n" . number_format($event->total_mined ?? 0, 0) . ' ISK'];
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Participants:*\n" . ($event->participant_count ?? 0)];
        } else {
            if ($event->start_time) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Start:*\n{$event->start_time->format('Y-m-d H:i')}"];
            }
        }

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $title]],
            ['type' => 'section', 'fields' => $fields],
        ];

        $payload = ['text' => $title, 'blocks' => $blocks];

        if ($webhook->slack_channel) {
            $payload['channel'] = $webhook->slack_channel;
        }
        if ($webhook->slack_username) {
            $payload['username'] = $webhook->slack_username;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);
            if ($response->successful()) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => "Slack returned status {$response->status()}: {$response->body()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to Slack: {$e->getMessage()}"];
        }
    }

    /**
     * Send event notification to custom webhook
     */
    protected function sendEventToCustom(WebhookConfiguration $webhook, string $eventType, MiningEvent $event, array $additionalData): array
    {
        $payload = array_merge([
            'event_type' => $eventType,
            'timestamp' => now()->toIso8601String(),
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'type' => $event->type,
                'type_label' => $event->getTypeLabel(),
                'tax_modifier' => $event->tax_modifier,
                'tax_modifier_label' => $event->getTaxModifierLabel(),
                'location_scope' => $event->location_scope,
                'location_name' => $event->getLocationName(),
                'start_time' => $event->start_time ? $event->start_time->toIso8601String() : null,
                'end_time' => $event->end_time ? $event->end_time->toIso8601String() : null,
                'total_mined' => $event->total_mined ?? 0,
                'participant_count' => $event->participant_count ?? 0,
                'status' => $event->status,
            ],
        ], $additionalData);

        $request = Http::timeout(10);

        if ($webhook->custom_headers && is_array($webhook->custom_headers)) {
            foreach ($webhook->custom_headers as $key => $value) {
                $request = $request->withHeader($key, $value);
            }
        }

        try {
            $response = $request->post($webhook->webhook_url, $payload);
            if ($response->successful()) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => "Custom webhook returned status {$response->status()}: {$response->body()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to custom webhook: {$e->getMessage()}"];
        }
    }

    // ============================================================================
    // TAX NOTIFICATIONS
    // ============================================================================

    /**
     * Send a tax-related notification to all configured webhooks
     *
     * @param string $eventType (tax_reminder, tax_invoice, tax_overdue)
     * @param array $data Tax notification data
     * @param int|null $corporationId
     * @return array Results for each webhook
     */
    public function sendTaxNotification(string $eventType, array $data, ?int $corporationId = null): array
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forEvent($eventType)
            ->where('type', 'discord')
            ->get()
            ->filter(function ($webhook) use ($corporationId) {
                return $webhook->corporation_id === null || $webhook->corporation_id == $corporationId;
            });

        if ($webhooks->isEmpty()) {
            Log::debug("WebhookService: No webhooks configured for tax event: {$eventType}");
            return [];
        }

        $results = [];

        foreach ($webhooks as $webhook) {
            try {
                $result = $this->sendTaxToWebhook($webhook, $eventType, $data);
                $results[$webhook->id] = $result;

                if ($result['success']) {
                    $webhook->recordSuccess();
                } else {
                    $webhook->recordFailure($result['error'] ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                Log::error("WebhookService: Exception sending tax notification to webhook {$webhook->id}", [
                    'error' => $e->getMessage(),
                    'event_type' => $eventType,
                ]);

                $webhook->recordFailure($e->getMessage());
                $results[$webhook->id] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Send tax notification to a specific webhook
     *
     * @param WebhookConfiguration $webhook
     * @param string $eventType
     * @param array $data
     * @return array
     */
    protected function sendTaxToWebhook(WebhookConfiguration $webhook, string $eventType, array $data): array
    {
        switch ($webhook->type) {
            case 'discord':
                return $this->sendTaxToDiscord($webhook, $eventType, $data);

            case 'slack':
                return $this->sendTaxToSlack($webhook, $eventType, $data);

            case 'custom':
                return $this->sendTaxToCustom($webhook, $eventType, $data);

            default:
                return ['success' => false, 'error' => "Unknown webhook type: {$webhook->type}"];
        }
    }

    /**
     * Send tax notification to Discord
     */
    protected function sendTaxToDiscord(WebhookConfiguration $webhook, string $eventType, array $data): array
    {
        $embed = $this->buildTaxDiscordEmbed($eventType, $data);

        $payload = ['embeds' => [$embed]];

        if ($webhook->discord_role_id) {
            $payload['content'] = $webhook->getDiscordRoleMention();
        }

        if ($webhook->discord_username) {
            $payload['username'] = $webhook->discord_username;
        }

        if ($webhook->discord_avatar_url) {
            $payload['avatar_url'] = $webhook->discord_avatar_url;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);

            if ($response->successful() || $response->status() === 204) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => "Discord returned status {$response->status()}: {$response->body()}",
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to Discord: {$e->getMessage()}"];
        }
    }

    /**
     * Build Discord embed for tax notifications
     */
    protected function buildTaxDiscordEmbed(string $eventType, array $data): array
    {
        $color = $this->getColorForEventType($eventType);
        $title = $this->getTitleForEventType($eventType);

        $embed = [
            'title' => $title,
            'color' => $color,
            'timestamp' => now()->toIso8601String(),
            'fields' => [],
            'footer' => ['text' => $this->getCorpName() . ' Mining Manager'],
        ];

        // Character name
        if (isset($data['character_name'])) {
            $embed['fields'][] = [
                'name' => '👤 Character',
                'value' => $data['character_name'],
                'inline' => true,
            ];
        }

        // Amount
        if (isset($data['formatted_amount'])) {
            $embed['fields'][] = [
                'name' => '💰 Amount',
                'value' => $data['formatted_amount'],
                'inline' => true,
            ];
        }

        // Due date
        if (isset($data['due_date'])) {
            $embed['fields'][] = [
                'name' => '📅 Due Date',
                'value' => $data['due_date'],
                'inline' => true,
            ];
        }

        // Days remaining (for reminders)
        if (isset($data['days_remaining'])) {
            $embed['fields'][] = [
                'name' => '⏳ Days Remaining',
                'value' => (string) $data['days_remaining'],
                'inline' => true,
            ];
        }

        // Days overdue
        if (isset($data['days_overdue'])) {
            $embed['fields'][] = [
                'name' => '⚠️ Days Overdue',
                'value' => (string) $data['days_overdue'],
                'inline' => true,
            ];
        }

        // Description
        $embed['description'] = match ($eventType) {
            'tax_reminder' => 'A mining tax payment is coming due. Please ensure timely payment.',
            'tax_invoice' => 'A new mining tax invoice has been generated.',
            'tax_overdue' => 'A mining tax payment is past due. Please make payment immediately.',
            default => '',
        };

        return $embed;
    }

    /**
     * Send tax notification to Slack
     */
    protected function sendTaxToSlack(WebhookConfiguration $webhook, string $eventType, array $data): array
    {
        $title = $this->getTitleForEventType($eventType);

        $fields = [];

        if (isset($data['character_name'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Character:*\n{$data['character_name']}"];
        }
        if (isset($data['formatted_amount'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Amount:*\n{$data['formatted_amount']}"];
        }
        if (isset($data['due_date'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Due Date:*\n{$data['due_date']}"];
        }
        if (isset($data['days_remaining'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Days Remaining:*\n{$data['days_remaining']}"];
        }
        if (isset($data['days_overdue'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Days Overdue:*\n{$data['days_overdue']}"];
        }

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $title]],
        ];

        if (!empty($fields)) {
            $blocks[] = ['type' => 'section', 'fields' => $fields];
        }

        $payload = ['text' => $title, 'blocks' => $blocks];

        if ($webhook->slack_channel) {
            $payload['channel'] = $webhook->slack_channel;
        }
        if ($webhook->slack_username) {
            $payload['username'] = $webhook->slack_username;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);
            if ($response->successful()) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => "Slack returned status {$response->status()}: {$response->body()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to Slack: {$e->getMessage()}"];
        }
    }

    /**
     * Send tax notification to custom webhook
     */
    protected function sendTaxToCustom(WebhookConfiguration $webhook, string $eventType, array $data): array
    {
        $payload = array_merge([
            'event_type' => $eventType,
            'timestamp' => now()->toIso8601String(),
            'tax' => [
                'character_id' => $data['character_id'] ?? null,
                'character_name' => $data['character_name'] ?? null,
                'amount' => $data['amount'] ?? null,
                'formatted_amount' => $data['formatted_amount'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'days_remaining' => $data['days_remaining'] ?? null,
                'days_overdue' => $data['days_overdue'] ?? null,
            ],
        ], $data);

        $request = Http::timeout(10);

        if ($webhook->custom_headers && is_array($webhook->custom_headers)) {
            foreach ($webhook->custom_headers as $key => $value) {
                $request = $request->withHeader($key, $value);
            }
        }

        try {
            $response = $request->post($webhook->webhook_url, $payload);
            if ($response->successful()) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => "Custom webhook returned status {$response->status()}: {$response->body()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to custom webhook: {$e->getMessage()}"];
        }
    }

    // ============================================================================
    // REPORT NOTIFICATIONS
    // ============================================================================

    /**
     * Send a report generated notification to all configured webhooks
     *
     * @param MiningReport $report
     * @param array $reportData
     * @return array Results for each webhook
     */
    public function sendReportNotification(MiningReport $report, array $reportData): array
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forEvent('report_generated')
            ->get();

        if ($webhooks->isEmpty()) {
            Log::debug("WebhookService: No webhooks configured for event type: report_generated");
            return [];
        }

        $results = [];

        foreach ($webhooks as $webhook) {
            try {
                $result = $this->sendReportToWebhook($webhook, $report, $reportData);
                $results[$webhook->id] = $result;

                if ($result['success']) {
                    $webhook->recordSuccess();
                } else {
                    $webhook->recordFailure($result['error'] ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                Log::error("WebhookService: Exception sending report notification to webhook {$webhook->id}", [
                    'error' => $e->getMessage(),
                    'report_id' => $report->id,
                ]);

                $webhook->recordFailure($e->getMessage());
                $results[$webhook->id] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Send report notification to a specific webhook
     *
     * @param WebhookConfiguration $webhook
     * @param MiningReport $report
     * @param array $reportData
     * @return array
     */
    public function sendReportToWebhook(WebhookConfiguration $webhook, MiningReport $report, array $reportData): array
    {
        switch ($webhook->type) {
            case 'discord':
                return $this->sendReportToDiscord($webhook, $report, $reportData);

            case 'slack':
                return $this->sendReportToSlack($webhook, $report, $reportData);

            case 'custom':
                return $this->sendReportToCustom($webhook, $report, $reportData);

            default:
                return ['success' => false, 'error' => "Unknown webhook type: {$webhook->type}"];
        }
    }

    /**
     * Send report notification to Discord
     *
     * @param WebhookConfiguration $webhook
     * @param MiningReport $report
     * @param array $reportData
     * @return array
     */
    protected function sendReportToDiscord(WebhookConfiguration $webhook, MiningReport $report, array $reportData): array
    {
        $embed = $this->buildReportDiscordEmbed($report, $reportData);

        $payload = ['embeds' => [$embed]];

        if ($webhook->discord_role_id) {
            $payload['content'] = $webhook->getDiscordRoleMention();
        }

        if ($webhook->discord_username) {
            $payload['username'] = $webhook->discord_username;
        }

        if ($webhook->discord_avatar_url) {
            $payload['avatar_url'] = $webhook->discord_avatar_url;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);

            if ($response->successful() || $response->status() === 204) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => "Discord returned status {$response->status()}: {$response->body()}",
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to Discord: {$e->getMessage()}"];
        }
    }

    /**
     * Send report notification to Slack
     *
     * @param WebhookConfiguration $webhook
     * @param MiningReport $report
     * @param array $reportData
     * @return array
     */
    protected function sendReportToSlack(WebhookConfiguration $webhook, MiningReport $report, array $reportData): array
    {
        $title = $this->getTitleForEventType('report_generated');

        $summary = $reportData['summary'] ?? [];
        $taxes = $reportData['taxes'] ?? [];
        $period = $reportData['period'] ?? [];

        $periodStr = isset($period['start'], $period['end'])
            ? "{$period['start']} to {$period['end']}"
            : 'N/A';

        $fields = [
            ['type' => 'mrkdwn', 'text' => "*Report Type:*\n" . ucfirst($report->report_type)],
            ['type' => 'mrkdwn', 'text' => "*Period:*\n{$periodStr}"],
            ['type' => 'mrkdwn', 'text' => "*Total Miners:*\n" . number_format($summary['unique_miners'] ?? 0)],
            ['type' => 'mrkdwn', 'text' => "*Total Value Mined:*\n" . number_format($summary['total_value'] ?? 0, 0) . ' ISK'],
        ];

        if (isset($taxes['total_owed'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Estimated Tax:*\n" . number_format($taxes['total_owed'], 0) . ' ISK'];
        }

        if (isset($taxes['collection_rate'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Collection Rate:*\n" . number_format($taxes['collection_rate'], 1) . '%'];
        }

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $title]],
            ['type' => 'section', 'fields' => $fields],
        ];

        $payload = ['text' => $title, 'blocks' => $blocks];

        if ($webhook->slack_channel) {
            $payload['channel'] = $webhook->slack_channel;
        }
        if ($webhook->slack_username) {
            $payload['username'] = $webhook->slack_username;
        }

        try {
            $response = Http::timeout(10)->post($webhook->webhook_url, $payload);
            if ($response->successful()) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => "Slack returned status {$response->status()}: {$response->body()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to Slack: {$e->getMessage()}"];
        }
    }

    /**
     * Send report notification to custom webhook
     *
     * @param WebhookConfiguration $webhook
     * @param MiningReport $report
     * @param array $reportData
     * @return array
     */
    protected function sendReportToCustom(WebhookConfiguration $webhook, MiningReport $report, array $reportData): array
    {
        $payload = array_merge([
            'event_type' => 'report_generated',
            'timestamp' => now()->toIso8601String(),
            'report' => [
                'id' => $report->id,
                'report_type' => $report->report_type,
                'start_date' => $report->start_date ? $report->start_date->toDateString() : null,
                'end_date' => $report->end_date ? $report->end_date->toDateString() : null,
                'format' => $report->format,
                'generated_at' => $report->generated_at ? $report->generated_at->toIso8601String() : now()->toIso8601String(),
                'generated_by' => $report->generated_by,
            ],
            'summary' => $reportData['summary'] ?? [],
            'taxes' => $reportData['taxes'] ?? [],
        ], $reportData);

        $request = Http::timeout(10);

        if ($webhook->custom_headers && is_array($webhook->custom_headers)) {
            foreach ($webhook->custom_headers as $key => $value) {
                $request = $request->withHeader($key, $value);
            }
        }

        try {
            $response = $request->post($webhook->webhook_url, $payload);
            if ($response->successful()) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => "Custom webhook returned status {$response->status()}: {$response->body()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => "Failed to send to custom webhook: {$e->getMessage()}"];
        }
    }

    /**
     * Build Discord embed for report notifications
     *
     * @param MiningReport $report
     * @param array $reportData
     * @return array
     */
    protected function buildReportDiscordEmbed(MiningReport $report, array $reportData): array
    {
        $color = $this->getColorForEventType('report_generated');
        $title = $this->getTitleForEventType('report_generated');

        $summary = $reportData['summary'] ?? [];
        $taxes = $reportData['taxes'] ?? [];
        $period = $reportData['period'] ?? [];

        $periodStr = isset($period['start'], $period['end'])
            ? "{$period['start']} to {$period['end']}"
            : 'N/A';

        $embed = [
            'title' => $title,
            'color' => $color,
            'timestamp' => now()->toIso8601String(),
            'fields' => [],
            'footer' => [
                'text' => $this->getCorpName() . ' Mining Manager | Generated ' . ($report->generated_at ? $report->generated_at->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s')),
            ],
        ];

        // Report Type
        $embed['fields'][] = [
            'name' => '📋 Report Type',
            'value' => ucfirst($report->report_type),
            'inline' => true,
        ];

        // Period
        $embed['fields'][] = [
            'name' => '📅 Period',
            'value' => $periodStr,
            'inline' => true,
        ];

        // Total Miners
        $embed['fields'][] = [
            'name' => '👥 Total Miners',
            'value' => number_format($summary['unique_miners'] ?? 0),
            'inline' => true,
        ];

        // Total Value Mined
        $embed['fields'][] = [
            'name' => '💰 Total Value Mined',
            'value' => number_format($summary['total_value'] ?? 0, 0) . ' ISK',
            'inline' => true,
        ];

        // Tax info - estimated vs final
        $isCurrentMonth = $taxes['is_current_month'] ?? false;

        if (isset($taxes['estimated_tax']) && $taxes['estimated_tax'] > 0) {
            $embed['fields'][] = [
                'name' => $isCurrentMonth ? '📊 Estimated Tax' : '📊 Total Tax',
                'value' => number_format($taxes['estimated_tax'], 0) . ' ISK',
                'inline' => true,
            ];
        }

        // For past months, show payment details
        if (!$isCurrentMonth) {
            if (isset($taxes['total_paid']) && $taxes['total_paid'] > 0) {
                $embed['fields'][] = [
                    'name' => '✅ Total Paid',
                    'value' => number_format($taxes['total_paid'], 0) . ' ISK',
                    'inline' => true,
                ];
            }

            if (isset($taxes['unpaid']) && $taxes['unpaid'] > 0) {
                $embed['fields'][] = [
                    'name' => '❌ Outstanding',
                    'value' => number_format($taxes['unpaid'], 0) . ' ISK',
                    'inline' => true,
                ];
            }

            if (isset($taxes['collection_rate'])) {
                $embed['fields'][] = [
                    'name' => '📈 Collection Rate',
                    'value' => number_format($taxes['collection_rate'], 1) . '%',
                    'inline' => true,
                ];
            }
        }

        $embed['description'] = $isCurrentMonth
            ? 'A new mining report has been generated. Tax values are estimated (month in progress).'
            : 'A new mining report has been generated and is ready for review.';

        return $embed;
    }

    // ============================================================================
    // SHARED HELPERS
    // ============================================================================

    /**
     * Get color for Discord embed based on event type
     *
     * @param string $eventType
     * @return int
     */
    protected function getColorForEventType(string $eventType): int
    {
        return match($eventType) {
            'critical_theft' => 0xFF0000,       // Red
            'active_theft' => 0xFF6B00,         // Orange-red
            'theft_detected' => 0xFFA500,       // Orange
            'incident_resolved' => 0x00FF00,    // Green
            'jackpot_detected' => 0xFFD700,     // Gold
            'moon_arrival' => 0x3498DB,         // Blue
            'event_created' => 0x3498DB,        // Blue
            'event_started' => 0x2ECC71,        // Green
            'event_completed' => 0x9B59B6,      // Purple
            'tax_reminder' => 0xF39C12,         // Amber
            'tax_invoice' => 0x3498DB,          // Blue
            'tax_overdue' => 0xE74C3C,          // Red
            'report_generated' => 0x3498DB,     // Blue
            default => 0xFFFF00,                // Yellow
        };
    }

    /**
     * Get title for event type
     *
     * @param string $eventType
     * @return string
     */
    protected function getTitleForEventType(string $eventType): string
    {
        return match($eventType) {
            'critical_theft' => 'CRITICAL THEFT DETECTED',
            'active_theft' => 'ACTIVE THEFT IN PROGRESS',
            'theft_detected' => 'Theft Incident Detected',
            'incident_resolved' => 'Theft Incident Resolved',
            'jackpot_detected' => 'JACKPOT MOON DETECTED!',
            'moon_arrival' => 'Moon Chunk Ready',
            'event_created' => '📅 New Mining Event',
            'event_started' => '🚀 Mining Event Started',
            'event_completed' => '🏁 Mining Event Completed',
            'tax_reminder' => '⏰ Tax Payment Reminder',
            'tax_invoice' => '📧 New Tax Invoice',
            'tax_overdue' => '❌ Overdue Tax Payment',
            'report_generated' => '📊 Mining Report Generated',
            default => 'Mining Manager Alert',
        };
    }

    /**
     * Get emoji for severity level
     *
     * @param string $severity
     * @return string
     */
    protected function getSeverityEmoji(string $severity): string
    {
        return match($severity) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };
    }

    /**
     * Get emoji for status
     *
     * @param string $status
     * @return string
     */
    protected function getStatusEmoji(string $status): string
    {
        return match($status) {
            'detected' => '🔓',
            'investigating' => '🔍',
            'resolved' => '✅',
            'false_alarm' => '❌',
            'removed_paid' => '💰',
            default => '❓',
        };
    }

    /**
     * Get the moon owner corporation name for notification footers.
     *
     * @return string
     */
    protected function getCorpName(): string
    {
        $settings = app(SettingsManagerService::class);

        $corpId = $settings->getSetting('general.moon_owner_corporation_id');
        if (!$corpId) {
            $corpId = $settings->getSetting('general.corporation_id');
        }

        if (!$corpId) {
            return 'Corporation';
        }

        return DB::table('corporation_infos')
            ->where('corporation_id', $corpId)
            ->value('name') ?? 'Corporation';
    }
}
