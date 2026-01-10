<?php

namespace MiningManager\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\TaxInvoice;
use Seat\Eseye\Cache\NullCache;
use Seat\Eseye\Configuration;
use Seat\Eseye\Containers\EsiAuthentication;
use Seat\Eseye\Eseye;
use Carbon\Carbon;
use Exception;

/**
 * Service for sending notifications through various channels
 * 
 * Supported channels:
 * - ESI (EVE in-game notifications)
 * - Slack
 * - Discord
 * 
 * Notification types:
 * - Tax payment reminders
 * - Tax invoice created
 * - Event started/completed
 * - Moon extraction ready
 * - Custom notifications
 */
class NotificationService
{
    /**
     * Notification channels
     */
    const CHANNEL_ESI = 'esi';
    const CHANNEL_SLACK = 'slack';
    const CHANNEL_DISCORD = 'discord';

    /**
     * Notification types
     */
    const TYPE_TAX_GENERATED = 'tax_generated';
    const TYPE_TAX_ANNOUNCEMENT = 'tax_announcement';
    const TYPE_TAX_REMINDER = 'tax_reminder';
    const TYPE_TAX_INVOICE = 'tax_invoice';
    const TYPE_TAX_OVERDUE = 'tax_overdue';
    const TYPE_EVENT_CREATED = 'event_created';
    const TYPE_EVENT_STARTED = 'event_started';
    const TYPE_EVENT_COMPLETED = 'event_completed';
    const TYPE_MOON_READY = 'moon_ready';
    const TYPE_CUSTOM = 'custom';

    /**
     * Settings manager
     */
    protected SettingsManagerService $settings;

    /**
     * Constructor
     */
    public function __construct(SettingsManagerService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Send a notification
     *
     * @param string $type Notification type
     * @param array $recipients Character IDs
     * @param array $data Notification data
     * @param array|null $channels Specific channels or null for configured channels
     * @return array Results by channel
     */
    public function send(string $type, array $recipients, array $data, ?array $channels = null): array
    {
        if (!$this->isEnabled()) {
            Log::info('Notifications disabled, skipping send');
            return ['skipped' => true];
        }

        // Check global per-type toggle (master override)
        if ($type !== self::TYPE_CUSTOM && !$this->isTypeGloballyEnabled($type)) {
            Log::info("Notification type '{$type}' is globally disabled, skipping");
            return ['skipped' => true, 'reason' => 'type_disabled'];
        }

        $channels = $channels ?? $this->getEnabledChannels();
        $results = [];

        foreach ($channels as $channel) {
            // Skip if this notification type is not enabled for this channel
            if (!$this->isTypeEnabledForChannel($type, $channel)) {
                continue;
            }

            try {
                $results[$channel] = match ($channel) {
                    self::CHANNEL_ESI => $this->sendViaESI($type, $recipients, $data),
                    self::CHANNEL_SLACK => $this->sendViaSlack($type, $recipients, $data),
                    self::CHANNEL_DISCORD => $this->sendViaWebhooks($type, $recipients, $data),
                    default => ['error' => 'Unknown channel']
                };
            } catch (Exception $e) {
                Log::error('Notification send failed', [
                    'channel' => $channel,
                    'type' => $type,
                    'error' => $e->getMessage()
                ]);
                $results[$channel] = ['error' => $e->getMessage()];
            }
        }

        $this->logNotification($type, $recipients, $channels, $results);

        return $results;
    }

    /**
     * Send tax reminder notification
     *
     * @param int $characterId
     * @param float $amount
     * @param Carbon $dueDate
     * @param int $daysRemaining
     * @return array
     */
    public function sendTaxReminder(int $characterId, float $amount, Carbon $dueDate, int $daysRemaining): array
    {
        $typeSettings = $this->settings->getTypeNotificationSettings('tax_reminder');

        $data = [
            'amount' => $amount,
            'due_date' => $dueDate->format('Y-m-d'),
            'days_remaining' => $daysRemaining,
            'formatted_amount' => number_format($amount, 2) . ' ISK',
            'show_amount' => $typeSettings['show_amount'],
            'tax_page_url' => $this->getTaxPageUrl(),
            'my_taxes_url' => $this->getMyTaxesUrl(),
            'help_url' => $this->getHelpPayUrl(),
            'is_personal' => true,
        ];

        return $this->send(self::TYPE_TAX_REMINDER, [$characterId], $data);
    }

    /**
     * Send tax invoice created notification
     *
     * @param TaxInvoice $invoice
     * @return array
     */
    public function sendTaxInvoice(TaxInvoice $invoice): array
    {
        $typeSettings = $this->settings->getTypeNotificationSettings('tax_invoice');

        $data = [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount,
            'due_date' => $invoice->due_date->format('Y-m-d'),
            'formatted_amount' => number_format($invoice->amount, 2) . ' ISK',
            'show_amount' => $typeSettings['show_amount'],
            'tax_page_url' => $this->getTaxPageUrl(),
            'my_taxes_url' => $this->getMyTaxesUrl(),
            'help_url' => $this->getHelpPayUrl(),
            'is_personal' => true,
        ];

        return $this->send(self::TYPE_TAX_INVOICE, [$invoice->character_id], $data);
    }

    /**
     * Send overdue tax notification
     *
     * @param int $characterId
     * @param float $amount
     * @param Carbon $dueDate
     * @param int $daysOverdue
     * @return array
     */
    public function sendTaxOverdue(int $characterId, float $amount, Carbon $dueDate, int $daysOverdue): array
    {
        $typeSettings = $this->settings->getTypeNotificationSettings('tax_overdue');

        $data = [
            'amount' => $amount,
            'due_date' => $dueDate->format('Y-m-d'),
            'days_overdue' => $daysOverdue,
            'formatted_amount' => number_format($amount, 2) . ' ISK',
            'show_amount' => $typeSettings['show_amount'],
            'tax_page_url' => $this->getTaxPageUrl(),
            'my_taxes_url' => $this->getMyTaxesUrl(),
            'help_url' => $this->getHelpPayUrl(),
            'is_personal' => true,
        ];

        return $this->send(self::TYPE_TAX_OVERDUE, [$characterId], $data);
    }

    /**
     * Send tax generated notification (broadcast)
     *
     * Sent when taxes are calculated for a period, notifying all members
     * with general payment info and instructions.
     *
     * @param string $periodLabel Human-readable period (e.g., "March 2026")
     * @param int $taxCount Number of tax records created
     * @param float $totalAmount Total ISK across all tax records
     * @param string $periodType monthly|biweekly|weekly
     * @param string|null $dueDate Due date for the taxes
     * @return array
     */
    public function sendTaxGenerated(string $periodLabel, int $taxCount, float $totalAmount, string $periodType = 'monthly', ?string $dueDate = null): array
    {
        $divisionName = $this->settings->getWalletDivisionName();
        $corpName = $this->getCorpName();

        $data = [
            'period_label' => $periodLabel,
            'period_type' => $periodType,
            'tax_count' => $taxCount,
            'total_amount' => $totalAmount,
            'formatted_amount' => number_format($totalAmount, 2) . ' ISK',
            'due_date' => $dueDate,
            'corp_name' => $corpName,
            'wallet_division' => $divisionName,
            'tax_code_prefix' => $this->settings->getSetting('tax_rates.tax_code_prefix', 'TAX-'),
            'my_taxes_url' => $this->getMyTaxesUrl(),
            'help_url' => $this->getHelpPayUrl(),
            'collect_url' => $this->getHelpCollectUrl(),
            'wallet_url' => $this->getBaseUrl() . '/mining-manager/tax/wallet',
        ];

        // This is a broadcast — no specific recipients, goes to all configured channels
        return $this->send(self::TYPE_TAX_GENERATED, [], $data);
    }

    /**
     * Send tax announcement notification (member-facing broadcast)
     *
     * General channel announcement informing members that new invoices are ready.
     * Shows due date and payment links — no ISK amounts or director details.
     *
     * @param string $periodLabel Human-readable period (e.g., "March 2026")
     * @param string $periodType monthly|biweekly|weekly
     * @param string|null $dueDate Due date for the taxes
     * @return array
     */
    public function sendTaxAnnouncement(string $periodLabel, string $periodType = 'monthly', ?string $dueDate = null): array
    {
        $corpName = $this->getCorpName();

        $data = [
            'period_label' => $periodLabel,
            'period_type' => $periodType,
            'due_date' => $dueDate,
            'corp_name' => $corpName,
            'my_taxes_url' => $this->getMyTaxesUrl(),
            'help_url' => $this->getHelpPayUrl(),
        ];

        // This is a broadcast — no specific recipients, goes to all configured channels
        return $this->send(self::TYPE_TAX_ANNOUNCEMENT, [], $data);
    }

    /**
     * Send event created notification
     *
     * @param MiningEvent $event
     * @return array
     */
    public function sendEventCreated(MiningEvent $event): array
    {
        $data = [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'event_type' => $event->getTypeLabel(),
            'event_type_key' => $event->type,
            'start_date' => $event->start_time->format('Y-m-d H:i'),
            'end_date' => $event->end_time ? $event->end_time->format('Y-m-d H:i') : null,
            'tax_modifier' => $event->tax_modifier,
            'tax_modifier_label' => $event->getTaxModifierLabel(),
            'location' => $event->getLocationName() ?? $event->getLocationScopeLabel(),
        ];

        return $this->sendBroadcast(self::TYPE_EVENT_CREATED, $data);
    }

    /**
     * Send event started notification
     *
     * @param MiningEvent $event
     * @param array $participantIds
     * @return array
     */
    public function sendEventStarted(MiningEvent $event, array $participantIds = []): array
    {
        $data = [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'event_type' => $event->getTypeLabel(),
            'event_type_key' => $event->type,
            'start_date' => $event->start_time->format('Y-m-d H:i'),
            'end_date' => $event->end_time ? $event->end_time->format('Y-m-d H:i') : null,
            'tax_modifier' => $event->tax_modifier,
            'tax_modifier_label' => $event->getTaxModifierLabel(),
            'location' => $event->getLocationName() ?? $event->getLocationScopeLabel(),
        ];

        // If no specific participants, notify all corp members
        if (empty($participantIds)) {
            return $this->sendBroadcast(self::TYPE_EVENT_STARTED, $data);
        }

        return $this->send(self::TYPE_EVENT_STARTED, $participantIds, $data);
    }

    /**
     * Send event completed notification
     *
     * @param MiningEvent $event
     * @param array $participantIds
     * @return array
     */
    public function sendEventCompleted(MiningEvent $event, array $participantIds = []): array
    {
        $data = [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'event_type' => $event->getTypeLabel(),
            'total_mined' => $event->total_mined ?? 0,
            'participants' => $event->participant_count ?? 0,
            'tax_modifier' => $event->tax_modifier,
            'tax_modifier_label' => $event->getTaxModifierLabel(),
            'location' => $event->getLocationName() ?? $event->getLocationScopeLabel(),
        ];

        if (empty($participantIds)) {
            return $this->sendBroadcast(self::TYPE_EVENT_COMPLETED, $data);
        }

        return $this->send(self::TYPE_EVENT_COMPLETED, $participantIds, $data);
    }

    /**
     * Send moon extraction ready notification
     *
     * @deprecated Moon notifications are handled directly by WebhookService::sendMoonNotification().
     * This method is kept for backward compatibility but is never called.
     *
     * @param int $structureId
     * @param Carbon $readyTime
     * @param array $recipients
     * @return array
     */
    public function sendMoonReady(int $structureId, Carbon $readyTime, array $recipients): array
    {
        $data = [
            'structure_id' => $structureId,
            'ready_time' => $readyTime->format('Y-m-d H:i'),
            'time_until_ready' => $readyTime->diffForHumans()
        ];

        return $this->send(self::TYPE_MOON_READY, $recipients, $data);
    }

    /**
     * Send custom notification
     *
     * @param string $message
     * @param array $recipients
     * @param array $additionalData
     * @return array
     */
    public function sendCustom(string $message, array $recipients, array $additionalData = []): array
    {
        $data = array_merge(['message' => $message], $additionalData);
        return $this->send(self::TYPE_CUSTOM, $recipients, $data);
    }

    /**
     * Send broadcast notification to all corp members
     *
     * @param string $type
     * @param array $data
     * @return array
     */
    public function sendBroadcast(string $type, array $data): array
    {
        $corpId = $this->settings->getSetting('general.corporation_id');

        if (!$corpId) {
            return ['error' => 'Corporation ID not configured'];
        }

        // Get all corp member character IDs from SeAT
        $memberIds = DB::table('corporation_members')
            ->where('corporation_id', $corpId)
            ->pluck('character_id')
            ->toArray();

        if (empty($memberIds)) {
            return ['error' => 'No corporation members found'];
        }

        return $this->send($type, $memberIds, $data);
    }

    /**
     * Send notification via ESI (in-game)
     *
     * @param string $type
     * @param array $recipients
     * @param array $data
     * @return array
     */
    protected function sendViaESI(string $type, array $recipients, array $data): array
    {
        $results = [
            'sent' => [],
            'failed' => []
        ];

        // Get configured sender character
        $senderId = $this->getSenderCharacterId();
        if (!$senderId) {
            Log::warning('ESI notifications: No sender character configured');
            return ['error' => 'No sender character configured. Set one in Settings > Notifications.'];
        }

        $token = $this->getCharacterToken($senderId);
        if (!$token) {
            Log::error('ESI notifications: No valid token for sender', ['sender_id' => $senderId]);
            return ['error' => 'Sender character has no valid mail token (esi-mail.send_mail.v1 scope required)'];
        }

        // Initialize Eseye client ONCE with the sender's token
        try {
            $esi = new Eseye(new EsiAuthentication([
                'access_token' => $token->access_token,
                'refresh_token' => $token->refresh_token,
                'token_expires' => $token->expires_at,
                'scopes' => explode(' ', $token->scopes)
            ]));
        } catch (Exception $e) {
            Log::error('ESI notifications: Failed to initialize Eseye client', ['error' => $e->getMessage()]);
            return ['error' => 'Failed to initialize ESI client: ' . $e->getMessage()];
        }

        $message = $this->formatMessageForESI($type, $data);

        foreach ($recipients as $characterId) {
            try {
                // Send FROM sender TO recipient
                $esi->invoke('post', '/characters/{character_id}/mail/', [
                    'character_id' => $senderId
                ], [
                    'approved_cost' => 0,
                    'body' => $message['body'],
                    'recipients' => [
                        [
                            'recipient_id' => $characterId,
                            'recipient_type' => 'character'
                        ]
                    ],
                    'subject' => $message['subject']
                ]);

                $results['sent'][] = $characterId;

                Log::info('ESI notification sent', [
                    'sender_id' => $senderId,
                    'recipient_id' => $characterId,
                    'type' => $type
                ]);
            } catch (Exception $e) {
                Log::error('Failed to send ESI notification', [
                    'sender_id' => $senderId,
                    'recipient_id' => $characterId,
                    'error' => $e->getMessage()
                ]);
                $results['failed'][] = $characterId;
            }
        }

        return $results;
    }

    /**
     * Get the configured sender character ID for EVE mail
     *
     * @return int|null
     */
    protected function getSenderCharacterId(): ?int
    {
        // Manual override takes priority
        $override = $this->settings->getSetting('notifications.evemail_sender_character_override');
        if ($override) {
            return (int) $override;
        }

        // Then dropdown selection
        $selected = $this->settings->getSetting('notifications.evemail_sender_character_id');
        if ($selected) {
            return (int) $selected;
        }

        return null;
    }

    /**
     * Resolve a character's Discord user ID via seat-connector
     *
     * @param int $characterId
     * @return string|null Discord user ID or null
     */
    protected function resolveDiscordUserId(int $characterId): ?string
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('seat_connector_users')) {
                return null;
            }

            // character_id -> refresh_tokens.user_id -> seat_connector_users
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->value('user_id');

            if (!$userId) {
                return null;
            }

            return DB::table('seat_connector_users')
                ->where('user_id', $userId)
                ->where('connector_type', 'discord')
                ->value('connector_id');
        } catch (Exception $e) {
            Log::debug('Failed to resolve Discord user ID', [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check if a notification type is a tax-related type
     *
     * @param string $type
     * @return bool
     */
    protected function isTaxNotificationType(string $type): bool
    {
        return in_array($type, [
            self::TYPE_TAX_REMINDER,
            self::TYPE_TAX_INVOICE,
            self::TYPE_TAX_OVERDUE,
        ]);
    }

    /**
     * Check if a notification type is enabled for a specific channel
     *
     * @param string $type
     * @param string $channel
     * @return bool
     */
    /**
     * Check if a notification type is globally enabled (master toggle).
     * Stored in notifications.enabled_types setting.
     */
    protected function isTypeGloballyEnabled(string $type): bool
    {
        $enabledTypes = $this->settings->getSetting('notifications.enabled_types', []);

        // If no toggles have been saved yet, all types are enabled by default
        if (empty($enabledTypes)) {
            return true;
        }

        $typeKey = match ($type) {
            self::TYPE_TAX_GENERATED => 'tax_generated',
            self::TYPE_TAX_ANNOUNCEMENT => 'tax_announcement',
            self::TYPE_TAX_REMINDER => 'tax_reminder',
            self::TYPE_TAX_INVOICE => 'tax_invoice',
            self::TYPE_TAX_OVERDUE => 'tax_overdue',
            self::TYPE_EVENT_CREATED => 'event_created',
            self::TYPE_EVENT_STARTED => 'event_started',
            self::TYPE_EVENT_COMPLETED => 'event_completed',
            self::TYPE_MOON_READY => 'moon_ready',
            default => null,
        };

        if ($typeKey === null) {
            return true;
        }

        return (bool) ($enabledTypes[$typeKey] ?? true);
    }

    protected function isTypeEnabledForChannel(string $type, string $channel): bool
    {
        $typeKey = match ($type) {
            self::TYPE_TAX_GENERATED => 'tax_generated',
            self::TYPE_TAX_ANNOUNCEMENT => 'tax_announcement',
            self::TYPE_TAX_REMINDER => 'tax_reminder',
            self::TYPE_TAX_INVOICE => 'tax_invoice',
            self::TYPE_TAX_OVERDUE => 'tax_overdue',
            self::TYPE_EVENT_CREATED => 'event_created',
            self::TYPE_EVENT_STARTED => 'event_started',
            self::TYPE_EVENT_COMPLETED => 'event_completed',
            self::TYPE_MOON_READY => 'moon_ready',
            self::TYPE_CUSTOM => 'custom',
            default => null,
        };

        // Custom type always passes through
        if ($typeKey === 'custom' || $typeKey === null) {
            return true;
        }

        if ($channel === self::CHANNEL_ESI) {
            $types = $this->settings->getSetting('notifications.evemail_types', []);
            return $types[$typeKey] ?? true;
        }

        if ($channel === self::CHANNEL_SLACK) {
            $types = $this->settings->getSetting('notifications.slack_types', []);
            return $types[$typeKey] ?? true;
        }

        // Discord uses webhook-level toggles, always allow through here
        return true;
    }

    /**
     * Send notification via Slack
     *
     * @param string $type
     * @param array $recipients
     * @param array $data
     * @return array
     */
    protected function sendViaSlack(string $type, array $recipients, array $data): array
    {
        $webhookUrl = $this->settings->getSetting('notifications.slack_webhook_url');

        if (!$webhookUrl) {
            return ['error' => 'Slack webhook URL not configured. Set one in Settings > Notifications.'];
        }

        try {
            $message = $this->formatMessageForSlack($type, $data);
            
            $response = Http::post($webhookUrl, $message);

            if ($response->successful()) {
                Log::info('Slack notification sent', ['type' => $type]);
                return ['success' => true, 'recipients' => count($recipients)];
            }

            return ['error' => 'Slack API error: ' . $response->status()];
        } catch (Exception $e) {
            Log::error('Failed to send Slack notification', [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Send notification via Discord
     *
     * Uses per-webhook configuration from webhook_configurations table.
     * Each webhook has its own toggles for which notification types to send.
     * Supports Discord user pinging for tax notifications via seat-connector.
     *
     * @param string $type
     * @param array $recipients
     * @param array $data
     * @return array
     */
    protected function sendViaWebhooks(string $type, array $recipients, array $data): array
    {
        // Map notification type to webhook event column
        $eventType = match ($type) {
            self::TYPE_TAX_GENERATED => 'tax_generated',
            self::TYPE_TAX_ANNOUNCEMENT => 'tax_announcement',
            self::TYPE_TAX_REMINDER => 'tax_reminder',
            self::TYPE_TAX_INVOICE => 'tax_invoice',
            self::TYPE_TAX_OVERDUE => 'tax_overdue',
            self::TYPE_EVENT_CREATED => 'event_created',
            self::TYPE_EVENT_STARTED => 'event_started',
            self::TYPE_EVENT_COMPLETED => 'event_completed',
            self::TYPE_MOON_READY => 'moon_arrival',
            default => null,
        };

        if (!$eventType) {
            return ['error' => 'Unsupported notification type for Discord webhooks'];
        }

        // Get webhooks that have this event type enabled
        $corporationId = $data['corporation_id'] ?? null;
        $webhooks = \MiningManager\Models\WebhookConfiguration::enabled()
            ->forEvent($eventType)
            ->when($corporationId, function ($query) use ($corporationId) {
                return $query->forCorporation($corporationId);
            })
            ->get();

        if ($webhooks->isEmpty()) {
            Log::debug("NotificationService: No webhooks configured for event: {$eventType}");
            return ['skipped' => true, 'reason' => 'No webhooks configured for this event type'];
        }

        $results = ['sent' => [], 'failed' => []];

        foreach ($webhooks as $webhook) {
            try {
                $sent = false;

                if ($webhook->type === 'discord') {
                    // Existing Discord logic — keep as-is
                    $message = $this->formatMessageForDiscord($type, $data);

                    // Add Discord user pings for tax notifications (per-type setting)
                    $pingContent = $this->buildDiscordPingContent($type, $recipients, $data);
                    if ($pingContent) {
                        $message['content'] = $pingContent;
                    }

                    // Add role mention from per-type settings
                    $typeSettings = $this->settings->getTypeNotificationSettings($eventType);
                    if ($typeSettings['ping_role'] && $typeSettings['role_id']) {
                        $roleMention = "<@&{$typeSettings['role_id']}>";
                        $message['content'] = ($message['content'] ?? '')
                            ? $roleMention . ' ' . ($message['content'] ?? '')
                            : $roleMention;
                    } elseif ($webhook->discord_role_id) {
                        $roleMention = $webhook->getDiscordRoleMention();
                        $message['content'] = ($message['content'] ?? '')
                            ? $roleMention . ' ' . ($message['content'] ?? '')
                            : $roleMention;
                    }

                    if ($webhook->discord_username) {
                        $message['username'] = $webhook->discord_username;
                    }

                    if ($webhook->discord_avatar_url) {
                        $message['avatar_url'] = $webhook->discord_avatar_url;
                    }

                    $response = Http::timeout(10)->post($webhook->webhook_url, $message);
                    $sent = $response->successful() || $response->status() === 204;

                    if (!$sent) {
                        $error = "Discord returned status {$response->status()}: {$response->body()}";
                        $webhook->recordFailure($error);
                        $results['failed'][] = ['webhook_id' => $webhook->id, 'error' => $error];
                    }
                } elseif ($webhook->type === 'slack') {
                    // Format for Slack
                    $message = $this->formatMessageForSlack($type, $data);
                    $response = Http::timeout(10)->post($webhook->webhook_url, $message);
                    $sent = $response->successful();

                    if (!$sent) {
                        $error = "Slack returned status {$response->status()}: {$response->body()}";
                        $webhook->recordFailure($error);
                        $results['failed'][] = ['webhook_id' => $webhook->id, 'error' => $error];
                    }
                } elseif ($webhook->type === 'custom') {
                    // Generic JSON payload for custom webhooks
                    $payload = [
                        'event_type' => $eventType,
                        'notification_type' => $type,
                        'data' => $data,
                        'timestamp' => now()->toIso8601String(),
                    ];
                    $response = Http::timeout(10)->post($webhook->webhook_url, $payload);
                    $sent = $response->successful();

                    if (!$sent) {
                        $error = "Custom webhook returned status {$response->status()}";
                        $webhook->recordFailure($error);
                        $results['failed'][] = ['webhook_id' => $webhook->id, 'error' => $error];
                    }
                }

                if ($sent) {
                    $webhook->recordSuccess();
                    $results['sent'][] = $webhook->id;
                    Log::info("Notification sent via {$webhook->type} webhook", [
                        'webhook_id' => $webhook->id,
                        'type' => $type,
                    ]);
                }
            } catch (Exception $e) {
                Log::error("Failed to send notification via {$webhook->type} webhook", [
                    'webhook_id' => $webhook->id,
                    'error' => $e->getMessage(),
                ]);
                $webhook->recordFailure($e->getMessage());
                $results['failed'][] = ['webhook_id' => $webhook->id, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Build Discord ping content for tax notifications
     *
     * Resolves character IDs to Discord user IDs via seat-connector
     * and builds mention strings based on settings.
     *
     * @param string $type
     * @param array $recipients Character IDs
     * @param array $data Notification data
     * @return string|null
     */
    protected function buildDiscordPingContent(string $type, array $recipients, array $data): ?string
    {
        // Map notification type constant to settings key
        $typeKey = match ($type) {
            self::TYPE_TAX_GENERATED => 'tax_generated',
            self::TYPE_TAX_ANNOUNCEMENT => 'tax_announcement',
            self::TYPE_TAX_REMINDER => 'tax_reminder',
            self::TYPE_TAX_INVOICE => 'tax_invoice',
            self::TYPE_TAX_OVERDUE => 'tax_overdue',
            self::TYPE_EVENT_CREATED => 'event_created',
            self::TYPE_EVENT_STARTED => 'event_started',
            self::TYPE_EVENT_COMPLETED => 'event_completed',
            self::TYPE_MOON_READY => 'moon_ready',
            default => null,
        };

        if (!$typeKey) {
            return null;
        }

        $typeSettings = $this->settings->getTypeNotificationSettings($typeKey);

        // Check if user pinging is enabled for this type
        if (!$typeSettings['ping_user']) {
            return null;
        }

        $mentions = [];
        foreach ($recipients as $characterId) {
            $discordId = $this->resolveDiscordUserId($characterId);
            if ($discordId) {
                $mentions[] = "<@{$discordId}>";
            }
        }

        if (empty($mentions)) {
            return null;
        }

        $mentionStr = implode(' ', $mentions);

        $action = match ($type) {
            self::TYPE_TAX_REMINDER => 'You have a tax payment coming due.',
            self::TYPE_TAX_INVOICE => 'A new tax invoice has been created for you.',
            self::TYPE_TAX_OVERDUE => 'Your tax payment is overdue!',
            default => 'You have a pending tax notification.',
        };

        if ($typeSettings['show_amount'] && isset($data['formatted_amount'])) {
            return "{$mentionStr} — {$action} Amount: {$data['formatted_amount']}";
        }

        return "{$mentionStr} — {$action}";
    }

    /**
     * Format message for ESI (EVE Mail)
     *
     * @param string $type
     * @param array $data
     * @return array
     */
    protected function formatMessageForESI(string $type, array $data): array
    {
        return match ($type) {
            self::TYPE_TAX_REMINDER => [
                'subject' => 'Mining Tax Payment Reminder',
                'body' => sprintf(
                    "Hello,\n\nThis is a reminder that you have an outstanding mining tax payment due.\n\n" .
                    "Amount: %s\nDue Date: %s\nDays Remaining: %d\n\n" .
                    "Please make your payment before the due date to avoid any penalties.\n\n" .
                    "Thank you,\n%s Management",
                    $data['formatted_amount'],
                    $data['due_date'],
                    $data['days_remaining'],
                    $this->getCorpName()
                )
            ],
            self::TYPE_TAX_INVOICE => [
                'subject' => 'Mining Tax Invoice',
                'body' => sprintf(
                    "Hello,\n\nA new mining tax invoice has been created for you.\n\n" .
                    "Amount: %s\nDue Date: %s\n" .
                    "Please use direct wallet transfer with your tax code.\n\n" .
                    "Thank you,\n%s Management",
                    $data['formatted_amount'],
                    $data['due_date'],
                    $this->getCorpName()
                )
            ],
            self::TYPE_TAX_OVERDUE => [
                'subject' => 'Overdue Mining Tax Payment',
                'body' => sprintf(
                    "Hello,\n\nYour mining tax payment is now overdue.\n\n" .
                    "Amount: %s\nDue Date: %s\nDays Overdue: %d\n\n" .
                    "Please make your payment as soon as possible.\n\n" .
                    "Thank you,\n%s Management",
                    $data['formatted_amount'],
                    $data['due_date'],
                    $data['days_overdue'],
                    $this->getCorpName()
                )
            ],
            self::TYPE_EVENT_CREATED => [
                'subject' => 'New Mining Event: ' . $data['event_name'],
                'body' => sprintf(
                    "A new mining event has been scheduled!\n\n" .
                    "Event: %s\nType: %s\nTax Modifier: %s\n" .
                    "Location: %s\nStart: %s\n%s\n\n" .
                    "Mark your calendars and get ready to mine!\n\n" .
                    "%s Management",
                    $data['event_name'],
                    $data['event_type'],
                    $data['tax_modifier_label'],
                    $data['location'],
                    $data['start_date'],
                    $data['end_date'] ? "End: {$data['end_date']}" : '',
                    $this->getCorpName()
                )
            ],
            self::TYPE_EVENT_STARTED => [
                'subject' => 'Mining Event Started: ' . $data['event_name'],
                'body' => sprintf(
                    "A mining event has started!\n\n" .
                    "Event: %s\nType: %s\nTax Modifier: %s\n" .
                    "Location: %s\nStart: %s\n%s\n\n" .
                    "Get mining now!\n\n" .
                    "%s Management",
                    $data['event_name'],
                    $data['event_type'],
                    $data['tax_modifier_label'],
                    $data['location'],
                    $data['start_date'],
                    $data['end_date'] ? "End: {$data['end_date']}" : '',
                    $this->getCorpName()
                )
            ],
            self::TYPE_EVENT_COMPLETED => [
                'subject' => 'Mining Event Completed: ' . $data['event_name'],
                'body' => sprintf(
                    "Mining event has completed!\n\n" .
                    "Event: %s\nType: %s\nTotal Mined: %s ISK\nParticipants: %d\n" .
                    "Tax Modifier: %s\n\n" .
                    "Thank you for your participation!\n\n" .
                    "%s Management",
                    $data['event_name'],
                    $data['event_type'],
                    number_format($data['total_mined'], 0),
                    $data['participants'],
                    $data['tax_modifier_label'],
                    $this->getCorpName()
                )
            ],
            self::TYPE_MOON_READY => [
                'subject' => 'Moon Extraction Ready',
                'body' => sprintf(
                    "A moon extraction is ready!\n\n" .
                    "Structure ID: %d\nReady: %s\n\n" .
                    "Get your barges ready!\n\n" .
                    "%s Management",
                    $data['structure_id'],
                    $data['time_until_ready'],
                    $this->getCorpName()
                )
            ],
            self::TYPE_CUSTOM => [
                'subject' => 'Mining Manager Notification',
                'body' => $data['message']
            ],
            default => [
                'subject' => 'Mining Manager Notification',
                'body' => json_encode($data)
            ]
        };
    }

    /**
     * Format message for Slack
     *
     * @param string $type
     * @param array $data
     * @return array
     */
    protected function formatMessageForSlack(string $type, array $data): array
    {
        $color = match ($type) {
            self::TYPE_TAX_OVERDUE => 'danger',
            self::TYPE_TAX_REMINDER => 'warning',
            self::TYPE_EVENT_CREATED => '#439FE0',
            self::TYPE_EVENT_STARTED, self::TYPE_EVENT_COMPLETED => 'good',
            default => '#439FE0'
        };

        $text = match ($type) {
            self::TYPE_TAX_GENERATED => "Mining Taxes Summary: {$data['tax_count']} accounts taxed — {$data['formatted_amount']}",
            self::TYPE_TAX_ANNOUNCEMENT => "New Mining Invoices — {$data['period_label']}. Check My Taxes for details.",
            self::TYPE_TAX_REMINDER => "Tax Payment Reminder: {$data['formatted_amount']} due {$data['due_date']}",
            self::TYPE_TAX_INVOICE => "New Tax Invoice: {$data['formatted_amount']} due {$data['due_date']}",
            self::TYPE_TAX_OVERDUE => "Overdue Tax: {$data['formatted_amount']} - {$data['days_overdue']} days overdue",
            self::TYPE_EVENT_CREATED => "New Event Created: {$data['event_name']}",
            self::TYPE_EVENT_STARTED => "Event Started: {$data['event_name']}",
            self::TYPE_EVENT_COMPLETED => "Event Completed: {$data['event_name']}",
            self::TYPE_MOON_READY => "Moon Extraction Ready",
            default => "Mining Manager Notification"
        };

        return [
            'attachments' => [
                [
                    'color' => $color,
                    'text' => $text,
                    'fields' => $this->formatFieldsForSlack($type, $data),
                    'footer' => $this->getCorpName() . ' Mining Manager',
                    'ts' => time()
                ]
            ]
        ];
    }

    /**
     * Format message for Discord
     *
     * @param string $type
     * @param array $data
     * @return array
     */
    protected function formatMessageForDiscord(string $type, array $data): array
    {
        $color = match ($type) {
            self::TYPE_TAX_OVERDUE => 15158332, // Red
            self::TYPE_TAX_REMINDER => 16776960, // Yellow
            self::TYPE_TAX_GENERATED => 3447003, // Teal
            self::TYPE_TAX_ANNOUNCEMENT => 4437216, // Blue
            self::TYPE_EVENT_CREATED => 4437216, // Blue
            self::TYPE_EVENT_STARTED, self::TYPE_EVENT_COMPLETED => 3066993, // Green
            default => 4437216 // Blue
        };

        $title = match ($type) {
            self::TYPE_TAX_GENERATED => '📋 Mining Taxes Summary',
            self::TYPE_TAX_ANNOUNCEMENT => '📢 New Mining Invoices',
            self::TYPE_TAX_REMINDER => '⏰ Tax Payment Reminder',
            self::TYPE_TAX_INVOICE => '📧 New Tax Invoice',
            self::TYPE_TAX_OVERDUE => '❌ Overdue Tax Payment',
            self::TYPE_EVENT_CREATED => '📅 New Mining Event',
            self::TYPE_EVENT_STARTED => '🚀 Mining Event Started',
            self::TYPE_EVENT_COMPLETED => '🏁 Mining Event Completed',
            self::TYPE_MOON_READY => '🌙 Moon Extraction Ready',
            default => '📢 Mining Manager Notification'
        };

        return [
            'embeds' => [
                [
                    'title' => $title,
                    'color' => $color,
                    'fields' => $this->formatFieldsForDiscord($type, $data),
                    'footer' => [
                        'text' => $this->getCorpName() . ' Mining Manager'
                    ],
                    'timestamp' => Carbon::now()->toIso8601String()
                ]
            ]
        ];
    }

    /**
     * Format fields for Slack
     *
     * @param string $type
     * @param array $data
     * @return array
     */
    protected function formatFieldsForSlack(string $type, array $data): array
    {
        return match ($type) {
            self::TYPE_TAX_GENERATED => array_values(array_filter([
                ['title' => 'Period', 'value' => $data['period_label'], 'short' => true],
                ['title' => 'Accounts Taxed', 'value' => (string) $data['tax_count'], 'short' => true],
                ['title' => 'Total Tax', 'value' => $data['formatted_amount'], 'short' => true],
                ['title' => 'Due Date', 'value' => $data['due_date'] ?? 'See tax page', 'short' => true],
                ['title' => 'Collection Info', 'value' => "Taxation, collection, and verification happen automatically. Monitor the Wallet Verification page for mismatched payments.", 'short' => false],
                isset($data['collect_url']) ? ['title' => 'Collection Guide', 'value' => '<' . $data['collect_url'] . '|How to Collect>', 'short' => true] : null,
                isset($data['wallet_url']) ? ['title' => 'Wallet Verification', 'value' => '<' . $data['wallet_url'] . '|Check Payments>', 'short' => true] : null,
            ])),
            self::TYPE_TAX_ANNOUNCEMENT => array_values(array_filter([
                ['title' => 'Period', 'value' => $data['period_label'], 'short' => true],
                ['title' => 'Due Date', 'value' => $data['due_date'] ?? 'See tax page', 'short' => true],
                ['title' => 'Info', 'value' => 'Mining tax invoices have been generated for this period. Check My Taxes to view your invoice and payment instructions.', 'short' => false],
                isset($data['my_taxes_url']) ? ['title' => 'My Taxes', 'value' => '<' . $data['my_taxes_url'] . '|View My Taxes>', 'short' => true] : null,
                isset($data['help_url']) ? ['title' => 'How to Pay', 'value' => '<' . $data['help_url'] . '|Payment Guide>', 'short' => true] : null,
            ])),
            self::TYPE_TAX_REMINDER, self::TYPE_TAX_INVOICE => array_values(array_filter([
                ($data['show_amount'] ?? true) ? ['title' => 'Amount', 'value' => $data['formatted_amount'], 'short' => true] : null,
                ['title' => 'Due Date', 'value' => $data['due_date'], 'short' => true],
                isset($data['my_taxes_url']) ? ['title' => 'My Taxes', 'value' => '<' . $data['my_taxes_url'] . '|View My Taxes>', 'short' => true] : null,
                isset($data['help_url']) ? ['title' => 'How to Pay', 'value' => '<' . $data['help_url'] . '|Payment Guide>', 'short' => true] : null,
            ])),
            self::TYPE_TAX_OVERDUE => array_values(array_filter([
                ($data['show_amount'] ?? true) ? ['title' => 'Amount', 'value' => $data['formatted_amount'], 'short' => true] : null,
                ['title' => 'Due Date', 'value' => $data['due_date'], 'short' => true],
                ['title' => 'Days Overdue', 'value' => (string) $data['days_overdue'], 'short' => true],
                isset($data['my_taxes_url']) ? ['title' => 'My Taxes', 'value' => '<' . $data['my_taxes_url'] . '|View My Taxes>', 'short' => true] : null,
                isset($data['help_url']) ? ['title' => 'How to Pay', 'value' => '<' . $data['help_url'] . '|Payment Guide>', 'short' => true] : null,
            ])),
            self::TYPE_EVENT_CREATED => [
                ['title' => 'Event', 'value' => $data['event_name'], 'short' => true],
                ['title' => 'Type', 'value' => $data['event_type'] ?? 'Mining Op', 'short' => true],
                ['title' => 'Tax Modifier', 'value' => $data['tax_modifier_label'] ?? 'Normal', 'short' => true],
                ['title' => 'Location', 'value' => $data['location'] ?? 'Any', 'short' => true],
                ['title' => 'Start', 'value' => $data['start_date'], 'short' => true],
                ['title' => 'End', 'value' => $data['end_date'] ?? 'Open', 'short' => true]
            ],
            self::TYPE_EVENT_STARTED => [
                ['title' => 'Event', 'value' => $data['event_name'], 'short' => true],
                ['title' => 'Tax Modifier', 'value' => $data['tax_modifier_label'] ?? 'Normal', 'short' => true],
                ['title' => 'Location', 'value' => $data['location'] ?? 'Any', 'short' => true],
                ['title' => 'End', 'value' => $data['end_date'] ?? 'Open', 'short' => true]
            ],
            self::TYPE_EVENT_COMPLETED => [
                ['title' => 'Event', 'value' => $data['event_name'], 'short' => true],
                ['title' => 'Total Mined', 'value' => isset($data['total_mined']) ? number_format($data['total_mined'], 2) . ' ISK' : 'N/A', 'short' => true],
                ['title' => 'Participants', 'value' => (string)($data['participants'] ?? 0), 'short' => true]
            ],
            default => []
        };
    }

    /**
     * Format fields for Discord
     *
     * @param string $type
     * @param array $data
     * @return array
     */
    protected function formatFieldsForDiscord(string $type, array $data): array
    {
        return match ($type) {
            self::TYPE_TAX_GENERATED => array_values(array_filter([
                ['name' => '📅 Period', 'value' => $data['period_label'], 'inline' => true],
                ['name' => '👥 Accounts Taxed', 'value' => (string) $data['tax_count'], 'inline' => true],
                ['name' => '💰 Total Tax', 'value' => $data['formatted_amount'], 'inline' => true],
                ['name' => '📆 Due Date', 'value' => $data['due_date'] ?? 'See tax page', 'inline' => true],
                ['name' => '🏦 Payment Wallet', 'value' => $data['wallet_division'], 'inline' => true],
                ['name' => 'ℹ️ Collection Info', 'value' => "Taxation, collection, and verification happen automatically.\nMonitor the **Wallet Verification** page for mismatched payments.", 'inline' => false],
                isset($data['collect_url']) ? ['name' => '📖 How to Collect', 'value' => '[Collection Guide](' . $data['collect_url'] . ')', 'inline' => true] : null,
                isset($data['wallet_url']) ? ['name' => '🔍 Wallet Verification', 'value' => '[Check Payments](' . $data['wallet_url'] . ')', 'inline' => true] : null,
            ])),
            self::TYPE_TAX_ANNOUNCEMENT => array_values(array_filter([
                ['name' => '📅 Period', 'value' => $data['period_label'], 'inline' => true],
                ['name' => '📆 Due Date', 'value' => $data['due_date'] ?? 'See tax page', 'inline' => true],
                ['name' => 'ℹ️ Info', 'value' => "Mining tax invoices have been generated for this period.\nCheck **My Taxes** to view your invoice and payment instructions.", 'inline' => false],
                isset($data['my_taxes_url']) ? ['name' => '📋 My Taxes', 'value' => '[View My Taxes](' . $data['my_taxes_url'] . ')', 'inline' => true] : null,
                isset($data['help_url']) ? ['name' => '❓ How to Pay', 'value' => '[Payment Guide](' . $data['help_url'] . ')', 'inline' => true] : null,
            ])),
            self::TYPE_TAX_REMINDER => array_values(array_filter([
                ($data['show_amount'] ?? true) ? ['name' => '💰 Amount', 'value' => $data['formatted_amount'], 'inline' => true] : null,
                ['name' => '📅 Due Date', 'value' => $data['due_date'], 'inline' => true],
                isset($data['days_remaining']) ? ['name' => '⏳ Days Remaining', 'value' => (string) $data['days_remaining'], 'inline' => true] : null,
                isset($data['my_taxes_url']) ? ['name' => '📋 My Taxes', 'value' => '[View My Taxes](' . $data['my_taxes_url'] . ')', 'inline' => true] : null,
                isset($data['help_url']) ? ['name' => '❓ How to Pay', 'value' => '[Payment Guide](' . $data['help_url'] . ')', 'inline' => true] : null,
            ])),
            self::TYPE_TAX_INVOICE => array_values(array_filter([
                ($data['show_amount'] ?? true) ? ['name' => '💰 Amount', 'value' => $data['formatted_amount'], 'inline' => true] : null,
                ['name' => '📅 Due Date', 'value' => $data['due_date'], 'inline' => true],
                isset($data['my_taxes_url']) ? ['name' => '📋 My Taxes', 'value' => '[View My Taxes](' . $data['my_taxes_url'] . ')', 'inline' => true] : null,
                isset($data['help_url']) ? ['name' => '❓ How to Pay', 'value' => '[Payment Guide](' . $data['help_url'] . ')', 'inline' => true] : null,
            ])),
            self::TYPE_TAX_OVERDUE => array_values(array_filter([
                ($data['show_amount'] ?? true) ? ['name' => '💰 Amount', 'value' => $data['formatted_amount'], 'inline' => true] : null,
                ['name' => '📅 Due Date', 'value' => $data['due_date'], 'inline' => true],
                ['name' => '⚠️ Days Overdue', 'value' => (string) $data['days_overdue'], 'inline' => true],
                isset($data['my_taxes_url']) ? ['name' => '📋 My Taxes', 'value' => '[View My Taxes](' . $data['my_taxes_url'] . ')', 'inline' => true] : null,
                isset($data['help_url']) ? ['name' => '❓ How to Pay', 'value' => '[Payment Guide](' . $data['help_url'] . ')', 'inline' => true] : null,
            ])),
            self::TYPE_EVENT_CREATED => [
                ['name' => 'Event', 'value' => $data['event_name'], 'inline' => false],
                ['name' => 'Type', 'value' => $data['event_type'] ?? 'Mining Op', 'inline' => true],
                ['name' => 'Tax Modifier', 'value' => $data['tax_modifier_label'] ?? 'Normal', 'inline' => true],
                ['name' => 'Location', 'value' => $data['location'] ?? 'Any', 'inline' => true],
                ['name' => 'Start', 'value' => $data['start_date'], 'inline' => true],
                ['name' => 'End', 'value' => $data['end_date'] ?? 'Open', 'inline' => true]
            ],
            self::TYPE_EVENT_STARTED => [
                ['name' => 'Event', 'value' => $data['event_name'], 'inline' => false],
                ['name' => 'Tax Modifier', 'value' => $data['tax_modifier_label'] ?? 'Normal', 'inline' => true],
                ['name' => 'Location', 'value' => $data['location'] ?? 'Any', 'inline' => true],
                ['name' => 'End', 'value' => $data['end_date'] ?? 'Open', 'inline' => true]
            ],
            self::TYPE_EVENT_COMPLETED => [
                ['name' => 'Event', 'value' => $data['event_name'], 'inline' => false],
                ['name' => 'Total Mined', 'value' => isset($data['total_mined']) ? number_format($data['total_mined'], 2) . ' ISK' : 'N/A', 'inline' => true],
                ['name' => 'Participants', 'value' => (string)($data['participants'] ?? 0), 'inline' => true]
            ],
            default => []
        };
    }

    /**
     * Get character refresh token from SeAT
     *
     * @param int $characterId
     * @return object|null
     */
    protected function getCharacterToken(int $characterId): ?object
    {
        return DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->whereRaw("FIND_IN_SET('esi-mail.send_mail.v1', scopes) > 0")
            ->first();
    }

    /**
     * Get corporation name
     *
     * @return string
     */
    /**
     * Get the tax page URL from settings or generate from current app URL.
     */
    protected function getBaseUrl(): string
    {
        return rtrim(config('app.url', ''), '/');
    }

    protected function getTaxPageUrl(): string
    {
        $customUrl = $this->settings->getNotificationSettings()['discord_ping_tax_page_url'] ?? '';
        if (!empty($customUrl)) {
            return rtrim($customUrl, '/');
        }

        return $this->getBaseUrl() . '/mining-manager/tax';
    }

    protected function getMyTaxesUrl(): string
    {
        return $this->getBaseUrl() . '/mining-manager/tax/my-taxes';
    }

    protected function getHelpPayUrl(): string
    {
        return $this->getBaseUrl() . '/mining-manager/help#how-to-pay';
    }

    protected function getHelpCollectUrl(): string
    {
        return $this->getBaseUrl() . '/mining-manager/help#how-to-collect';
    }

    protected function getCorpName(): string
    {
        // Use moon owner corporation (the structure-owning corp), not the tax context corporation
        $corpId = $this->settings->getSetting('general.moon_owner_corporation_id');

        if (!$corpId) {
            // Fallback to main corporation_id if moon owner not set
            $corpId = $this->settings->getSetting('general.corporation_id');
        }

        if (!$corpId) {
            return 'Corporation';
        }

        $corp = DB::table('corporation_infos')
            ->where('corporation_id', $corpId)
            ->first();

        return $corp->name ?? 'Corporation';
    }

    /**
     * Check if notifications are enabled
     *
     * At least one channel must be enabled for notifications to be active.
     *
     * @return bool
     */
    protected function isEnabled(): bool
    {
        return (bool) $this->settings->getSetting('notifications.evemail_enabled', false)
            || (bool) $this->settings->getSetting('notifications.slack_enabled', false)
            || $this->hasEnabledDiscordWebhooks();
    }

    /**
     * Check if any Discord webhooks are enabled for tax notifications
     *
     * @return bool
     */
    protected function hasEnabledDiscordWebhooks(): bool
    {
        try {
            return \MiningManager\Models\WebhookConfiguration::enabled()
                ->where('type', 'discord')
                ->where(function ($q) {
                    $q->where('notify_tax_reminder', true)
                      ->orWhere('notify_tax_invoice', true)
                      ->orWhere('notify_tax_overdue', true)
                      ->orWhere('notify_event_created', true)
                      ->orWhere('notify_event_started', true)
                      ->orWhere('notify_event_completed', true)
                      ->orWhere('notify_moon_arrival', true);
                })
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get enabled notification channels
     *
     * @return array
     */
    protected function getEnabledChannels(): array
    {
        $channels = [];

        if ((bool) $this->settings->getSetting('notifications.evemail_enabled', false)) {
            $channels[] = self::CHANNEL_ESI;
        }

        if ((bool) $this->settings->getSetting('notifications.slack_enabled', false)) {
            $channels[] = self::CHANNEL_SLACK;
        }

        // Discord uses per-webhook configuration, always include if any webhooks exist
        if ($this->hasEnabledDiscordWebhooks()) {
            $channels[] = self::CHANNEL_DISCORD;
        }

        return $channels;
    }

    /**
     * Log notification
     *
     * @param string $type
     * @param array $recipients
     * @param array $channels
     * @param array $results
     * @return void
     */
    protected function logNotification(string $type, array $recipients, array $channels, array $results): void
    {
        try {
            DB::table('mining_notification_log')->insert([
                'type' => $type,
                'recipients' => json_encode($recipients),
                'channels' => json_encode($channels),
                'results' => json_encode($results),
                'created_at' => Carbon::now()
            ]);
        } catch (Exception $e) {
            Log::error('Failed to log notification', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get notification history
     *
     * @param int $limit
     * @param array $filters
     * @return array
     */
    public function getHistory(int $limit = 50, array $filters = []): array
    {
        $query = DB::table('mining_notification_log')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->get()->toArray();
    }

    /**
     * Get notification statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $total = DB::table('mining_notification_log')->count();
        
        $byType = DB::table('mining_notification_log')
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $recent = DB::table('mining_notification_log')
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        return [
            'total' => $total,
            'by_type' => $byType,
            'last_7_days' => $recent,
            'enabled' => $this->isEnabled(),
            'channels' => $this->getEnabledChannels()
        ];
    }

    /**
     * Test notification channel
     *
     * @param string $channel
     * @return array
     */
    public function testChannel(string $channel): array
    {
        $testData = [
            'message' => 'This is a test notification from Mining Manager',
            'test_time' => Carbon::now()->toDateTimeString()
        ];

        return $this->send(self::TYPE_CUSTOM, [], $testData, [$channel]);
    }
}
