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

        $channels = $channels ?? $this->getEnabledChannels();
        $results = [];

        foreach ($channels as $channel) {
            try {
                $results[$channel] = match ($channel) {
                    self::CHANNEL_ESI => $this->sendViaESI($type, $recipients, $data),
                    self::CHANNEL_SLACK => $this->sendViaSlack($type, $recipients, $data),
                    self::CHANNEL_DISCORD => $this->sendViaDiscord($type, $recipients, $data),
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
        if (!$this->settings->getSetting('notification_tax_reminders', true)) {
            return ['skipped' => true];
        }

        $data = [
            'amount' => $amount,
            'due_date' => $dueDate->format('Y-m-d'),
            'days_remaining' => $daysRemaining,
            'formatted_amount' => number_format($amount, 2) . ' ISK'
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
        $data = [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount,
            'due_date' => $invoice->due_date->format('Y-m-d'),
            'formatted_amount' => number_format($invoice->amount, 2) . ' ISK'
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
        $data = [
            'amount' => $amount,
            'due_date' => $dueDate->format('Y-m-d'),
            'days_overdue' => $daysOverdue,
            'formatted_amount' => number_format($amount, 2) . ' ISK'
        ];

        return $this->send(self::TYPE_TAX_OVERDUE, [$characterId], $data);
    }

    /**
     * Send event created notification
     *
     * @param MiningEvent $event
     * @return array
     */
    public function sendEventCreated(MiningEvent $event): array
    {
        if (!$this->settings->getSetting('notification_event_updates', true)) {
            return ['skipped' => true];
        }

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
        if (!$this->settings->getSetting('notification_event_updates', true)) {
            return ['skipped' => true];
        }

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
        if (!$this->settings->getSetting('notification_event_updates', true)) {
            return ['skipped' => true];
        }

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

        foreach ($recipients as $characterId) {
            try {
                $message = $this->formatMessageForESI($type, $data);
                
                // Get refresh token for the character
                $token = $this->getCharacterToken($characterId);
                
                if (!$token) {
                    $results['failed'][] = $characterId;
                    continue;
                }

                // Initialize Eseye client
                $config = Configuration::getInstance();
                $esi = new Eseye(new EsiAuthentication([
                    'access_token' => $token->access_token,
                    'refresh_token' => $token->refresh_token,
                    'token_expires' => $token->expires_at,
                    'scopes' => explode(' ', $token->scopes)
                ]));

                // Send EVE mail
                $response = $esi->invoke('post', '/characters/{character_id}/mail/', [
                    'character_id' => $characterId
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
                    'character_id' => $characterId,
                    'type' => $type
                ]);
            } catch (Exception $e) {
                Log::error('Failed to send ESI notification', [
                    'character_id' => $characterId,
                    'error' => $e->getMessage()
                ]);
                $results['failed'][] = $characterId;
            }
        }

        return $results;
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
        $webhookUrl = $this->settings->getSetting('slack_webhook_url');
        
        if (!$webhookUrl) {
            return ['error' => 'Slack webhook URL not configured'];
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
     * @param string $type
     * @param array $recipients
     * @param array $data
     * @return array
     */
    protected function sendViaDiscord(string $type, array $recipients, array $data): array
    {
        $webhookUrl = $this->settings->getSetting('discord_webhook_url');
        
        if (!$webhookUrl) {
            return ['error' => 'Discord webhook URL not configured'];
        }

        try {
            $message = $this->formatMessageForDiscord($type, $data);
            
            $response = Http::post($webhookUrl, $message);

            if ($response->successful()) {
                Log::info('Discord notification sent', ['type' => $type]);
                return ['success' => true, 'recipients' => count($recipients)];
            }

            return ['error' => 'Discord API error: ' . $response->status()];
        } catch (Exception $e) {
            Log::error('Failed to send Discord notification', [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
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
            self::TYPE_EVENT_CREATED => 4437216, // Blue
            self::TYPE_EVENT_STARTED, self::TYPE_EVENT_COMPLETED => 3066993, // Green
            default => 4437216 // Blue
        };

        $title = match ($type) {
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
            self::TYPE_TAX_REMINDER, self::TYPE_TAX_INVOICE => [
                ['title' => 'Amount', 'value' => $data['formatted_amount'], 'short' => true],
                ['title' => 'Due Date', 'value' => $data['due_date'], 'short' => true]
            ],
            self::TYPE_TAX_OVERDUE => [
                ['title' => 'Amount', 'value' => $data['formatted_amount'], 'short' => true],
                ['title' => 'Days Overdue', 'value' => $data['days_overdue'], 'short' => true]
            ],
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
            self::TYPE_TAX_REMINDER, self::TYPE_TAX_INVOICE => [
                ['name' => 'Amount', 'value' => $data['formatted_amount'], 'inline' => true],
                ['name' => 'Due Date', 'value' => $data['due_date'], 'inline' => true]
            ],
            self::TYPE_TAX_OVERDUE => [
                ['name' => 'Amount', 'value' => $data['formatted_amount'], 'inline' => true],
                ['name' => 'Days Overdue', 'value' => (string)$data['days_overdue'], 'inline' => true]
            ],
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
    protected function getCorpName(): string
    {
        $corpId = $this->settings->getSetting('general.corporation_id');
        
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
     * @return bool
     */
    protected function isEnabled(): bool
    {
        return $this->settings->getSetting('notifications_enabled', true);
    }

    /**
     * Get enabled notification channels
     *
     * @return array
     */
    protected function getEnabledChannels(): array
    {
        return $this->settings->getSetting('notification_channels', [self::CHANNEL_ESI]);
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
