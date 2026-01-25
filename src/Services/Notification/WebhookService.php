<?php

namespace MiningManager\Services\Notification;

use MiningManager\Models\WebhookConfiguration;
use MiningManager\Models\TheftIncident;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                'text' => 'Mining Manager - Theft Detection',
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
            'value' => $incident->detected_at ? $incident->detected_at->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s'),
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
            'detected_at' => $incident->detected_at->toIso8601String(),
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
                'detected_at' => $incident->detected_at ? $incident->detected_at->toIso8601String() : now()->toIso8601String(),
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
            'status' => 'open',
            'detected_at' => now(),
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

    /**
     * Get color for Discord embed based on event type
     *
     * @param string $eventType
     * @return int
     */
    protected function getColorForEventType(string $eventType): int
    {
        return match($eventType) {
            'critical_theft' => 0xFF0000, // Red
            'active_theft' => 0xFF6B00,   // Orange-red
            'theft_detected' => 0xFFA500, // Orange
            'incident_resolved' => 0x00FF00, // Green
            default => 0xFFFF00, // Yellow
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
            'critical_theft' => '🚨 CRITICAL THEFT DETECTED',
            'active_theft' => '🔥 ACTIVE THEFT IN PROGRESS',
            'theft_detected' => '⚠️ Theft Incident Detected',
            'incident_resolved' => '✅ Theft Incident Resolved',
            default => '⚠️ Theft Alert',
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
            'open' => '🔓',
            'investigating' => '🔍',
            'resolved' => '✅',
            'dismissed' => '❌',
            default => '❓',
        };
    }
}
