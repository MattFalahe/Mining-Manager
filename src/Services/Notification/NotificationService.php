<?php

namespace MiningManager\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Notification\Concerns\WebhookDispatchTrait;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MiningReport;
use MiningManager\Models\TaxInvoice;
use MiningManager\Models\TheftIncident;
use Seat\Eseye\Cache\NullCache;
use Seat\Eseye\Configuration;
use Seat\Eseye\Containers\EsiAuthentication;
use Seat\Eseye\Eseye;
use Carbon\Carbon;
use Exception;

/**
 * Single consolidated notification dispatcher for Mining Manager.
 *
 * Supported channels:
 *  - ESI (EVE in-game mail — tax + event notifications)
 *  - Slack (legacy global webhook URL)
 *  - Discord / Slack / Custom (per-webhook configurations table)
 *
 * Supported notification types:
 *  - Tax: tax_reminder, tax_invoice, tax_overdue, tax_generated, tax_announcement
 *  - Event: event_created, event_started, event_completed
 *  - Moon: moon_ready (chunk arrival), jackpot_detected
 *  - Theft: theft_detected, critical_theft, active_theft, incident_resolved
 *  - Report: report_generated
 *  - Custom (ad-hoc message)
 *
 * History: consolidated from the original two-dispatcher design
 * (NotificationService + WebhookService) across Phases A-F of the
 * notification consolidation, 2026-04-23. Shared webhook dispatch
 * infrastructure (role mentions, corp scoping, retry, getCorpName)
 * lives in WebhookDispatchTrait.
 */
class NotificationService
{
    use WebhookDispatchTrait;

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
    const TYPE_JACKPOT_DETECTED = 'jackpot_detected';
    const TYPE_MOON_CHUNK_UNSTABLE = 'moon_chunk_unstable';
    const TYPE_EXTRACTION_AT_RISK = 'extraction_at_risk';
    const TYPE_EXTRACTION_LOST = 'extraction_lost';
    const TYPE_THEFT_DETECTED = 'theft_detected';
    const TYPE_CRITICAL_THEFT = 'critical_theft';
    const TYPE_ACTIVE_THEFT = 'active_theft';
    const TYPE_INCIDENT_RESOLVED = 'incident_resolved';
    const TYPE_REPORT_GENERATED = 'report_generated';
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
            // Miner's current corp — lets sendViaWebhooks extend scope to
            // include per-corp webhooks so individual mining-group directors
            // can receive tax notifications for just their own members.
            'miner_corporation_id' => $this->resolveMinerCorporationId($characterId),
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

        // Resolve the deadline: prefer the underlying mining_tax's due_date
        // (the authoritative source), fall back to the invoice's expires_at
        // mirror (GenerateTaxInvoicesCommand copies due_date there), fall
        // back to 'N/A' if neither is set. The tax_invoices table has no
        // due_date column of its own — that's why expires_at is the mirror
        // field. Earlier versions read $invoice->due_date and crashed with
        // a null-format error (fixed 2026-04-23).
        $miningTaxDueDate = $invoice->miningTax?->due_date;
        $dueDateDisplay = $miningTaxDueDate
            ? $miningTaxDueDate->format('Y-m-d')
            : ($invoice->expires_at ? $invoice->expires_at->format('Y-m-d') : 'N/A');

        $data = [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount,
            'due_date' => $dueDateDisplay,
            'formatted_amount' => number_format($invoice->amount, 2) . ' ISK',
            'show_amount' => $typeSettings['show_amount'],
            'tax_page_url' => $this->getTaxPageUrl(),
            'my_taxes_url' => $this->getMyTaxesUrl(),
            'help_url' => $this->getHelpPayUrl(),
            'is_personal' => true,
            'miner_corporation_id' => $this->resolveMinerCorporationId((int) $invoice->character_id),
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
            'miner_corporation_id' => $this->resolveMinerCorporationId($characterId),
        ];

        return $this->send(self::TYPE_TAX_OVERDUE, [$characterId], $data);
    }

    /**
     * Look up a character's current corporation_id from SeAT's authoritative
     * `character_affiliations` table. Returns null on fake IDs (e.g. the
     * diagnostic tool's 123456789 placeholder) or if the character hasn't
     * been loaded into SeAT yet. When null, per-corp webhook routing is
     * skipped — the notification still fires to global + tax-program-corp
     * webhooks.
     */
    protected function resolveMinerCorporationId(int $characterId): ?int
    {
        if ($characterId <= 0) {
            return null;
        }
        $corpId = DB::table('character_affiliations')
            ->where('character_id', $characterId)
            ->value('corporation_id');
        return $corpId ? (int) $corpId : null;
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
            // Event's target corp — lets sendViaWebhooks scope the notification
            // to webhooks bound to that corp (plus NULL admin + tax program
            // corp). Null for "universal" events → admin-only delivery.
            'event_corporation_id' => $event->corporation_id ? (int) $event->corporation_id : null,
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
            'event_corporation_id' => $event->corporation_id ? (int) $event->corporation_id : null,
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
            'event_corporation_id' => $event->corporation_id ? (int) $event->corporation_id : null,
        ];

        if (empty($participantIds)) {
            return $this->sendBroadcast(self::TYPE_EVENT_COMPLETED, $data);
        }

        return $this->send(self::TYPE_EVENT_COMPLETED, $participantIds, $data);
    }

    /**
     * Send a "mining report generated" notification to all subscribed webhooks.
     *
     * Reports are GLOBAL scope — every enabled webhook subscribed to the
     * `report_generated` event receives the notification regardless of
     * corporation_id. Replaces the previous WebhookService::sendReportNotification()
     * path as of Phase B of the notification consolidation.
     *
     * @param MiningReport $report The report that was just generated
     * @param array $reportData The aggregated report payload (summary, taxes, period, ...)
     * @return array Result map from send() — webhook IDs sent vs failed
     */
    public function sendReportGenerated(MiningReport $report, array $reportData): array
    {
        $summary = $reportData['summary'] ?? [];
        $taxes = $reportData['taxes'] ?? [];
        $period = $reportData['period'] ?? [];

        $periodStr = isset($period['start'], $period['end'])
            ? "{$period['start']} to {$period['end']}"
            : 'N/A';

        $isCurrentMonth = $taxes['is_current_month'] ?? false;
        $generatedAt = $report->generated_at ? $report->generated_at : Carbon::now();

        $data = [
            // Discord/Slack embed data
            'report_id' => $report->id,
            'report_type' => $report->report_type,
            'period_str' => $periodStr,
            'period' => $period,
            'unique_miners' => $summary['unique_miners'] ?? 0,
            'total_value' => $summary['total_value'] ?? 0,
            'is_current_month' => $isCurrentMonth,
            'estimated_tax' => $taxes['estimated_tax'] ?? 0,
            'total_paid' => $taxes['total_paid'] ?? 0,
            'unpaid' => $taxes['unpaid'] ?? 0,
            'collection_rate' => $taxes['collection_rate'] ?? null,

            // Discord embed extras (description + custom footer suffix)
            'description' => $isCurrentMonth
                ? 'A new mining report has been generated. Tax values are estimated (month in progress).'
                : 'A new mining report has been generated and is ready for review.',
            'footer_extra' => ' | Generated ' . $generatedAt->format('Y-m-d H:i:s'),

            // Custom webhook payload extras (formatCustomPayload reads these)
            'format' => $report->format ?? null,
            'generated_at_iso' => $generatedAt->toIso8601String(),
            'generated_by' => $report->generated_by ?? null,
            'raw_summary' => $summary,
            'raw_taxes' => $taxes,
            'raw_report_data' => $reportData,
        ];

        return $this->send(self::TYPE_REPORT_GENERATED, [], $data);
    }

    /**
     * Send a "moon chunk arrived / ready for mining" notification.
     *
     * Corp-scoped via the shared WebhookDispatchTrait — only webhooks that
     * are global or assigned to the moon owner corp receive it. Replaces
     * the previous WebhookService::sendMoonNotification('moon_arrival', ...)
     * path as of Phase C of the notification consolidation.
     *
     * Expected keys in $data (all optional, gracefully filtered if missing):
     *   moon_name, structure_name, chunk_arrival_time, auto_fracture_time,
     *   estimated_value, ore_summary, extraction_url, extraction_id
     *
     * @param array $data
     * @return array Result map from send()
     */
    public function sendMoonArrival(array $data): array
    {
        $data['description'] = $data['description'] ?? 'A moon chunk is ready for mining!';
        return $this->send(self::TYPE_MOON_READY, [], $data);
    }

    /**
     * Send a "moon chunk going unstable soon" SAFETY warning for capital pilots.
     *
     * Corp-scoped (moon owner). Fired ~2 hours before a chunk enters the
     * PLUGIN's unstable state — which is fractured_at + 48h, NOT raw ESI
     * natural_decay_time. The plugin models a richer lifecycle than CCP:
     *
     *     chunk_arrival → fractured_at → 48h ready → 2h UNSTABLE → expired
     *
     * See MoonExtraction::getUnstableStartTime() for the authoritative
     * computation. Trigger logic lives in CheckExtractionArrivalsCommand's
     * second pass; MoonExtractionService::sendMoonChunkUnstableNotification()
     * does the actual data-shaping from the extraction model.
     *
     * Unstable chunks historically attract hostile gangs — this warning
     * gives Rorqual / Orca pilots time to dock up or warp to safety before
     * the unstable phase begins.
     *
     * Expected keys in $data (all optional, filtered by formatters if missing):
     *   moon_name, structure_name, extraction_id, extraction_url,
     *   natural_decay_time  (display string for the unstable-start time
     *                        e.g. '2026-04-25 18:30 UTC'; key name kept
     *                        for formatter compatibility),
     *   time_until_unstable (display string e.g. '1h 47m'),
     *   estimated_value     (int ISK — helps pilots decide whether to linger)
     *
     * Unlike tax_invoice (which fires N times per batched cron run), this
     * fires ONCE per extraction — role pings are appropriate and desired
     * here so Rorqual pilots get a fast high-visibility alert.
     *
     * @param array $data
     * @return array Result map from send()
     */
    public function sendMoonChunkUnstable(array $data): array
    {
        $data['description'] = $data['description']
            ?? '⚠️ This chunk will enter **unstable state** soon (last 2 hours of the 50-hour post-fracture window). Capital ship pilots (Rorquals, Orcas) should dock up or warp to safety — unstable chunks are known hotspots for hostile activity.';
        return $this->send(self::TYPE_MOON_CHUNK_UNSTABLE, [], $data);
    }

    /**
     * Send an extraction_at_risk notification — cross-plugin threat warning.
     *
     * Fires when Structure Manager detects an Athanor/Tatara running an active
     * moon extraction is in danger (fuel critical, under attack, or in a
     * reinforcement timer). Driven by a `structure.alert.*` event on Manager
     * Core's EventBus → StructureAlertHandler → this method.
     *
     * The embed title, color, and description vary by `alert_flavor`:
     *   - fuel_critical     → 🔥 MOON CHUNK COMPROMISED — Fuel Critical
     *   - shield_reinforced → ⚠️ EXTRACTION IN DANGER — Shield Down
     *   - armor_reinforced  → 🚨 EXTRACTION IN DANGER — Armor Timer
     *   - hull_reinforced   → 💀 MOON CHUNK DESTABILISED — Final Timer
     *
     * Uses the structure's owner corp (from the SM event payload) for
     * corp scoping — parallels the event-dispatch-corp model used by
     * event_created/event_started/event_completed.
     *
     * Requires BOTH Manager Core AND Structure Manager installed; early-
     * returns (skipped) if either is missing, so a legacy install without
     * MC/SM can still toggle the flag in settings without breakage.
     *
     * Expected keys in $data (all optional except structure_corporation_id
     * for corp-scoping; formatters filter missing keys):
     *   alert_flavor       (string — one of the four above)
     *   moon_name, structure_name, system_name, extraction_url,
     *   days_remaining     (float — fuel flavor only),
     *   hours_remaining    (float — fuel flavor only),
     *   fuel_expires       (ISO8601 string — fuel flavor only),
     *   timer_ends_at      (ISO8601 string — reinforced flavors only),
     *   estimated_value    (int ISK),
     *   structure_corporation_id (int — for corp scoping; = event publisher's structure owner)
     *
     * @param array $data
     * @return array Result map from send()
     */
    public function sendExtractionAtRisk(array $data): array
    {
        // Hard gate: without MC/SM infrastructure this type can't meaningfully fire.
        // We still pass through send() so logging/diagnostic parity works, but mark
        // the skip reason explicitly. Settings UI greys the toggle when missing.
        if (!class_exists('ManagerCore\\Services\\EventBus')
            || !class_exists('StructureManager\\Helpers\\FuelCalculator')) {
            Log::info('sendExtractionAtRisk: cross-plugin dependency missing (Manager Core or Structure Manager)', [
                'manager_core' => class_exists('ManagerCore\\Services\\EventBus'),
                'structure_manager' => class_exists('StructureManager\\Helpers\\FuelCalculator'),
            ]);
            return ['skipped' => true, 'reason' => 'Manager Core or Structure Manager not installed'];
        }

        $data['description'] = $data['description']
            ?? $this->resolveExtractionAtRiskDescription($data['alert_flavor'] ?? 'generic');

        return $this->send(self::TYPE_EXTRACTION_AT_RISK, [], $data);
    }

    /**
     * Send an extraction_lost notification — post-mortem for a destroyed
     * structure that had an active extraction.
     *
     * Separate from extraction_at_risk because the recipient set is
     * typically different (management / finance rather than fleet ops) and
     * the follow-up is different: tax reconciliation, "no chunk this cycle"
     * announcement to miners, insurance claim, etc.
     *
     * Driven by SM's `structure.alert.destroyed` event (detection design
     * deferred to a future SM session — see memory doc
     * project_structure_manager_destruction_detection.md).
     *
     * Same MC+SM gating as extraction_at_risk.
     *
     * Expected keys in $data:
     *   moon_name, structure_name, system_name, extraction_url,
     *   destroyed_at         (ISO8601),
     *   detection_source     ('notification' | 'grace_period' | 'killmail'),
     *   final_timer_result   (display string),
     *   chunk_value          (int ISK — what was lost),
     *   structure_corporation_id (int — for corp scoping)
     *
     * @param array $data
     * @return array Result map from send()
     */
    public function sendExtractionLost(array $data): array
    {
        if (!class_exists('ManagerCore\\Services\\EventBus')
            || !class_exists('StructureManager\\Helpers\\FuelCalculator')) {
            Log::info('sendExtractionLost: cross-plugin dependency missing (Manager Core or Structure Manager)', [
                'manager_core' => class_exists('ManagerCore\\Services\\EventBus'),
                'structure_manager' => class_exists('StructureManager\\Helpers\\FuelCalculator'),
            ]);
            return ['skipped' => true, 'reason' => 'Manager Core or Structure Manager not installed'];
        }

        $data['description'] = $data['description']
            ?? '☠️ **The structure running this extraction has been destroyed.** The chunk is lost. Tax reconciliation and miner communication recommended. Check the linked extraction for last-known ore composition and value at time of loss.';

        return $this->send(self::TYPE_EXTRACTION_LOST, [], $data);
    }

    /**
     * Resolve the default description text for an extraction_at_risk alert
     * based on the flavor (fuel_critical, shield_reinforced, armor_reinforced,
     * hull_reinforced). Used when the caller didn't supply a description.
     *
     * @param string $flavor
     * @return string
     */
    protected function resolveExtractionAtRiskDescription(string $flavor): string
    {
        return match ($flavor) {
            'fuel_critical' => '🔥 **This refinery is running out of fuel mid-extraction.** If fuel runs dry the structure goes offline and the moon drill stops — chunk mining halts. Top up fuel blocks now or the extraction may be lost.',
            'shield_reinforced' => '⚠️ **Shield depleted — structure entering first reinforcement timer.** Defensive window opens when the timer expires. Call defense fleet. Chunk is still recoverable if the structure holds.',
            'armor_reinforced' => '🚨 **Armor depleted — structure in second reinforcement timer (24h).** This is the strategic timer. Muster capitals and defense fleet now. If armor timer ends undefended, hull timer starts next.',
            'hull_reinforced' => '💀 **Final timer — structure in hull reinforcement.** This is the last stand. Lose this timer and the structure explodes and the chunk is lost permanently. All hands on deck.',
            default => '⚠️ **Refinery running an active extraction is under threat.** Check the linked extraction and respond immediately.',
        };
    }

    /**
     * Send a theft-detected notification.
     *
     * @param TheftIncident $incident
     * @param array $additionalData Extra keys merged into custom webhook payload; optional `incident_url`.
     * @return array Result map from send()
     */
    public function sendTheftDetected(TheftIncident $incident, array $additionalData = []): array
    {
        return $this->send(self::TYPE_THEFT_DETECTED, [], $this->buildTheftData($incident, $additionalData));
    }

    /**
     * Send a critical-theft notification (high-severity escalation).
     *
     * @param TheftIncident $incident
     * @param array $additionalData
     * @return array Result map from send()
     */
    public function sendCriticalTheft(TheftIncident $incident, array $additionalData = []): array
    {
        return $this->send(self::TYPE_CRITICAL_THEFT, [], $this->buildTheftData($incident, $additionalData));
    }

    /**
     * Send an active-theft-in-progress notification (character continues
     * mining while unpaid).
     *
     * $additionalData should include `new_mining_value` (float — ISK value
     * of the new mining since the incident was first detected) and
     * optionally `last_activity` (display-formatted timestamp string).
     *
     * @param TheftIncident $incident
     * @param array $additionalData
     * @return array Result map from send()
     */
    public function sendActiveTheft(TheftIncident $incident, array $additionalData = []): array
    {
        return $this->send(self::TYPE_ACTIVE_THEFT, [], $this->buildTheftData($incident, $additionalData));
    }

    /**
     * Send an "incident resolved" notification when a director marks a theft
     * incident resolved or false-alarm in the UI.
     *
     * @param TheftIncident $incident
     * @param array $additionalData Optional keys: `resolved_by`, `incident_url`
     * @return array Result map from send()
     */
    public function sendIncidentResolved(TheftIncident $incident, array $additionalData = []): array
    {
        return $this->send(self::TYPE_INCIDENT_RESOLVED, [], $this->buildTheftData($incident, $additionalData));
    }

    /**
     * Public helper — returns the human-readable title string for an event
     * type, matching what Discord/Slack embed formatters use. Convenient
     * for diagnostic / preview UIs that want the title without building
     * the whole embed.
     *
     * @param string $eventType Webhook event-column key (e.g. 'theft_detected')
     * @return string
     */
    public function getEventTitle(string $eventType): string
    {
        $typeConst = match ($eventType) {
            'tax_generated' => self::TYPE_TAX_GENERATED,
            'tax_announcement' => self::TYPE_TAX_ANNOUNCEMENT,
            'tax_reminder' => self::TYPE_TAX_REMINDER,
            'tax_invoice' => self::TYPE_TAX_INVOICE,
            'tax_overdue' => self::TYPE_TAX_OVERDUE,
            'event_created' => self::TYPE_EVENT_CREATED,
            'event_started' => self::TYPE_EVENT_STARTED,
            'event_completed' => self::TYPE_EVENT_COMPLETED,
            'moon_arrival', 'moon_ready' => self::TYPE_MOON_READY,
            'jackpot_detected' => self::TYPE_JACKPOT_DETECTED,
            'moon_chunk_unstable' => self::TYPE_MOON_CHUNK_UNSTABLE,
            'extraction_at_risk' => self::TYPE_EXTRACTION_AT_RISK,
            'extraction_lost' => self::TYPE_EXTRACTION_LOST,
            'theft_detected' => self::TYPE_THEFT_DETECTED,
            'critical_theft' => self::TYPE_CRITICAL_THEFT,
            'active_theft' => self::TYPE_ACTIVE_THEFT,
            'incident_resolved' => self::TYPE_INCIDENT_RESOLVED,
            'report_generated' => self::TYPE_REPORT_GENERATED,
            default => self::TYPE_CUSTOM,
        };

        $embed = $this->formatMessageForDiscord($typeConst, []);
        return $embed['embeds'][0]['title'] ?? 'Mining Manager Alert';
    }

    /**
     * Dispatch a minimal "webhook is active" ping to a single webhook for
     * configuration verification.
     *
     * Bypasses the full broadcast pipeline — POSTs directly to the target
     * webhook only, with no scope filtering and no per-type toggles. The
     * message itself is deliberately generic ("✅ Webhook Active") so the
     * user can tell the wiring works without seeing sample incident/tax
     * data they'd only want to see in a real notification.
     *
     * Used by Settings → Webhooks "Test" button and the Diagnostic tool's
     * basic webhook test endpoint.
     *
     * @param \MiningManager\Models\WebhookConfiguration $webhook
     * @return array ['success' => bool, 'error' => string?]
     */
    public function testWebhook($webhook): array
    {
        $corpName = $this->getCorpName();

        try {
            if ($webhook->type === 'discord') {
                $message = [
                    'embeds' => [[
                        'title' => '✅ Webhook Active',
                        'description' => 'This webhook is correctly configured and able to receive messages from Mining Manager.',
                        'color' => 0x2ECC71, // Green
                        'fields' => [
                            ['name' => 'Webhook Name', 'value' => $webhook->name ?? 'Unnamed', 'inline' => true],
                            ['name' => 'Test Time', 'value' => Carbon::now()->format('Y-m-d H:i:s') . ' UTC', 'inline' => true],
                        ],
                        'footer' => ['text' => $corpName . ' Mining Manager'],
                        'timestamp' => Carbon::now()->toIso8601String(),
                    ]],
                ];
                if ($webhook->discord_username) {
                    $message['username'] = $webhook->discord_username;
                }
                // No avatar_url override — Discord webhooks have their own
                // avatar setting in the channel UI (Edit Channel →
                // Integrations → edit webhook → upload). That's the
                // canonical place to configure it; we don't duplicate as a
                // per-MM-row override.
                $response = $this->postWithRetry($webhook->webhook_url, $message);
                return ($response->successful() || $response->status() === 204)
                    ? ['success' => true]
                    : ['success' => false, 'error' => "Discord returned status {$response->status()}: {$response->body()}"];
            }

            if ($webhook->type === 'slack') {
                $message = [
                    'text' => '✅ Webhook Active',
                    'attachments' => [[
                        'color' => 'good',
                        'text' => 'This webhook is correctly configured and able to receive messages from Mining Manager.',
                        'fields' => [
                            ['title' => 'Webhook Name', 'value' => $webhook->name ?? 'Unnamed', 'short' => true],
                            ['title' => 'Test Time', 'value' => Carbon::now()->format('Y-m-d H:i:s') . ' UTC', 'short' => true],
                        ],
                        'footer' => $corpName . ' Mining Manager',
                        'ts' => time(),
                    ]],
                ];
                if ($webhook->slack_channel) {
                    $message['channel'] = $webhook->slack_channel;
                }
                if ($webhook->slack_username) {
                    $message['username'] = $webhook->slack_username;
                }
                $response = $this->postWithRetry($webhook->webhook_url, $message);
                return $response->successful()
                    ? ['success' => true]
                    : ['success' => false, 'error' => "Slack returned status {$response->status()}: {$response->body()}"];
            }

            if ($webhook->type === 'custom') {
                $payload = [
                    'event_type' => 'webhook_test',
                    'status' => 'active',
                    'message' => 'This webhook is correctly configured and able to receive messages from Mining Manager.',
                    'webhook_name' => $webhook->name ?? 'Unnamed',
                    'corporation' => $corpName,
                    'timestamp' => Carbon::now()->toIso8601String(),
                ];

                $request = Http::timeout(10);
                if ($webhook->custom_headers && is_array($webhook->custom_headers)) {
                    foreach ($webhook->custom_headers as $key => $value) {
                        $request = $request->withHeader($key, $value);
                    }
                }
                $response = $request->post($webhook->webhook_url, $payload);
                return $response->successful()
                    ? ['success' => true]
                    : ['success' => false, 'error' => "Custom webhook returned status {$response->status()}: {$response->body()}"];
            }

            return ['success' => false, 'error' => "Unknown webhook type: {$webhook->type}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Shared builder — flattens a TheftIncident model + caller-supplied
     * additionalData into the single $data shape the theft formatters
     * consume (character_name, severity_label, ore_value, tax_owed,
     * detected_at_display, status_label, new_mining_value, last_activity,
     * incident_url). All optional — missing keys get gracefully filtered.
     *
     * @param TheftIncident $incident
     * @param array $additionalData
     * @return array
     */
    /**
     * Build the canonical theft notification data payload.
     *
     * Public so the diagnostic preview UI can call it directly rather
     * than via reflection. No side effects — pure data shape construction.
     */
    public function buildTheftData(TheftIncident $incident, array $additionalData): array
    {
        $severityLabel = ucfirst((string) ($incident->severity ?? 'medium'));
        $severityEmoji = match ($incident->severity ?? 'medium') {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };
        $statusEmoji = match ($incident->status ?? 'detected') {
            'detected' => '🔓',
            'investigating' => '🔍',
            'resolved' => '✅',
            'false_alarm' => '❌',
            'removed_paid' => '💰',
            default => '❓',
        };

        $incidentDate = $incident->incident_date ?? Carbon::now();

        $data = [
            'incident_id' => $incident->id,
            'character_id' => $incident->character_id,
            'character_name' => $incident->character_name ?? ('Character ID: ' . $incident->character_id),
            'severity' => $incident->severity,
            'severity_label' => $severityEmoji . ' ' . $severityLabel,
            'ore_value' => $incident->ore_value ?? 0,
            'tax_owed' => $incident->tax_owed ?? 0,
            'status' => $incident->status,
            'status_label' => $statusEmoji . ' ' . ucfirst((string) ($incident->status ?? 'detected')),
            'detected_at_display' => $incidentDate->format('Y-m-d H:i:s'),
            'detected_at_iso' => $incidentDate->toIso8601String(),
            'activity_count' => $incident->activity_count ?? 1,
            // Custom webhook payload passes additionalData verbatim at the
            // top level (matches old sendToCustomWebhook behaviour).
            '_additional_data' => $additionalData,
        ];

        // Merge everything else (e.g. new_mining_value, last_activity,
        // incident_url, resolved_by) directly into $data so the Discord
        // + Slack formatters can read them.
        return array_merge($data, $additionalData);
    }

    /**
     * Send a "jackpot moon detected" notification.
     *
     * Corp-scoped. Replaces the previous WebhookService::sendMoonNotification(
     * 'jackpot_detected', ...) path.
     *
     * If `$data['reported_by']` is set, the notification is framed as a
     * manual fleet-member report ("awaiting verification"). Otherwise it's
     * framed as an auto-detected confirmed jackpot.
     *
     * Expected keys in $data: moon_name, structure_name, system_name,
     * detected_by, jackpot_ores, jackpot_percentage, extraction_id,
     * (optional reported_by).
     *
     * @param array $data
     * @return array Result map from send()
     */
    public function sendJackpotDetected(array $data): array
    {
        if (!isset($data['description'])) {
            $data['description'] = isset($data['reported_by'])
                ? 'A jackpot moon extraction has been reported by a fleet member. Will be verified automatically when mining data arrives.'
                : 'A jackpot moon extraction has been confirmed! Miners found +100% variant ores in the belt.';
        }
        return $this->send(self::TYPE_JACKPOT_DETECTED, [], $data);
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
        $corpId = $this->settings->getTaxProgramCorporationId();

        if (!$corpId) {
            Log::warning("NotificationService::sendBroadcast — tax program corporation is not configured, type={$type}");
            return ['error' => 'Corporation ID not configured'];
        }

        // Inject corporation_id into data so downstream webhook dispatch can
        // scope correctly via sendViaWebhooks(). This was missing and caused
        // the forCorporation() scope to never apply for broadcast notifications.
        $data['corporation_id'] = $data['corporation_id'] ?? (int) $corpId;

        // Get all corp member character IDs from SeAT
        $memberIds = DB::table('corporation_members')
            ->where('corporation_id', $corpId)
            ->pluck('character_id')
            ->toArray();

        if (empty($memberIds)) {
            Log::warning("NotificationService::sendBroadcast — no members found in corporation_members for corp {$corpId}, type={$type}. Broadcasting to webhooks only.");
            // Still dispatch to webhooks — they don't need member IDs.
            // Passing an empty recipients array is fine; webhooks ignore it.
            return $this->send($type, [], $data);
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

        // Initialize Eseye client ONCE with the sender's token.
        //
        // We use $token->token (the magic accessor on the SeAT v5 RefreshToken
        // model) rather than $token->access_token (the raw column). The
        // accessor auto-refreshes the access token when SeAT's stored copy
        // has expired but the refresh_token is still valid — so Eseye gets
        // a guaranteed-fresh token. Pre-fix the raw column read could pass
        // a stale access token, forcing Eseye to try the refresh path
        // itself and surface ESI auth errors mid-send instead of MM
        // catching the bad-token state up-front.
        //
        // getCharacterToken() already returned null when ->token was empty,
        // so by the time we get here the auto-refresh has succeeded.
        try {
            $esi = new Eseye(new EsiAuthentication([
                'access_token' => $token->token,
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
            self::TYPE_JACKPOT_DETECTED => 'jackpot_detected',
            self::TYPE_MOON_CHUNK_UNSTABLE => 'moon_chunk_unstable',
            self::TYPE_EXTRACTION_AT_RISK => 'extraction_at_risk',
            self::TYPE_EXTRACTION_LOST => 'extraction_lost',
            self::TYPE_THEFT_DETECTED => 'theft_detected',
            self::TYPE_CRITICAL_THEFT => 'critical_theft',
            self::TYPE_ACTIVE_THEFT => 'active_theft',
            self::TYPE_INCIDENT_RESOLVED => 'incident_resolved',
            self::TYPE_REPORT_GENERATED => 'report_generated',
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
            self::TYPE_JACKPOT_DETECTED => 'jackpot_detected',
            self::TYPE_MOON_CHUNK_UNSTABLE => 'moon_chunk_unstable',
            self::TYPE_EXTRACTION_AT_RISK => 'extraction_at_risk',
            self::TYPE_EXTRACTION_LOST => 'extraction_lost',
            self::TYPE_THEFT_DETECTED => 'theft_detected',
            self::TYPE_CRITICAL_THEFT => 'critical_theft',
            self::TYPE_ACTIVE_THEFT => 'active_theft',
            self::TYPE_INCIDENT_RESOLVED => 'incident_resolved',
            self::TYPE_REPORT_GENERATED => 'report_generated',
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

            // Shared retry helper — respects Retry-After on 429, retries 5xx,
            // and times out at 10s per attempt.
            $response = $this->postWithRetry($webhookUrl, $message);

            if ($response->successful()) {
                Log::info('Slack notification sent', ['type' => $type]);
                $this->recordLegacySlackSuccess();
                return ['success' => true, 'recipients' => count($recipients)];
            }

            $error = 'Slack API error: ' . $response->status();
            $this->recordLegacySlackFailure($error);
            return ['error' => $error];
        } catch (Exception $e) {
            Log::error('Failed to send Slack notification', [
                'error' => $e->getMessage()
            ]);
            $this->recordLegacySlackFailure($e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Record a successful legacy-global-Slack dispatch in the persistent
     * health metric. Per-webhook rows in `webhook_configurations` already
     * track this via `recordSuccess`/`recordFailure` on the model;
     * pre-fix the legacy global path (single URL stored in
     * `notifications.slack_webhook_url`) had no equivalent — operators
     * had to grep logs to find out their Slack webhook was broken.
     *
     * Stored as plain settings rows so we don't need a new schema. Read
     * by the Notification Settings tab + diagnostic page (future) to
     * surface the counts.
     */
    protected function recordLegacySlackSuccess(): void
    {
        try {
            $current = (int) $this->settings->getSetting('notifications.slack_legacy_success_count', 0);
            $this->settings->updateSetting('notifications.slack_legacy_success_count', $current + 1, 'integer');
            $this->settings->updateSetting('notifications.slack_legacy_last_success_at', Carbon::now()->toIso8601String(), 'string');
            // Clear the last-error string on a successful dispatch — gives
            // operators a clear "we recovered" signal instead of stale
            // error text persisting after the issue self-healed.
            $this->settings->updateSetting('notifications.slack_legacy_last_error', '', 'string');
        } catch (\Throwable $e) {
            // Defensive — settings table missing or write failed. Don't
            // let metric bookkeeping crash the dispatch path.
        }
    }

    /**
     * Companion to recordLegacySlackSuccess — increments failure_count
     * and stores the most recent error string.
     */
    protected function recordLegacySlackFailure(string $error): void
    {
        try {
            $current = (int) $this->settings->getSetting('notifications.slack_legacy_failure_count', 0);
            $this->settings->updateSetting('notifications.slack_legacy_failure_count', $current + 1, 'integer');
            $this->settings->updateSetting('notifications.slack_legacy_last_failure_at', Carbon::now()->toIso8601String(), 'string');
            $this->settings->updateSetting('notifications.slack_legacy_last_error', substr($error, 0, 1000), 'string');
        } catch (\Throwable $e) {
            // Same defensive pattern.
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
            // TYPE_MOON_READY (constant value 'moon_ready') deliberately
            // maps to the LEGACY webhook column name 'moon_arrival'.
            // The model column is `notify_moon_arrival` (see
            // WebhookConfiguration::$fillable line 75) — kept for
            // backward compat with installs that have already saved
            // webhook configurations against the original column name.
            // Renaming the column would break every existing webhook's
            // event toggle. The user-facing label uses "Moon Ready" /
            // "Moon Arrival" interchangeably; only the column stayed
            // pinned to its original spelling.
            self::TYPE_MOON_READY => 'moon_arrival',
            self::TYPE_JACKPOT_DETECTED => 'jackpot_detected',
            self::TYPE_MOON_CHUNK_UNSTABLE => 'moon_chunk_unstable',
            self::TYPE_EXTRACTION_AT_RISK => 'extraction_at_risk',
            self::TYPE_EXTRACTION_LOST => 'extraction_lost',
            self::TYPE_THEFT_DETECTED => 'theft_detected',
            self::TYPE_CRITICAL_THEFT => 'critical_theft',
            self::TYPE_ACTIVE_THEFT => 'active_theft',
            self::TYPE_INCIDENT_RESOLVED => 'incident_resolved',
            self::TYPE_REPORT_GENERATED => 'report_generated',
            default => null,
        };

        if (!$eventType) {
            return ['error' => 'Unsupported notification type for Discord webhooks'];
        }

        // Webhook scoping rules:
        //  - Report notifications (report_generated): GLOBAL — reports
        //    aggregate across the install, not a single corp.
        //  - Individual tax notifications (tax_reminder / tax_invoice /
        //    tax_overdue): scoped to NULL + Tax Program Corp + the MINER'S
        //    OWN corp. Lets each mining-group director see their own
        //    members' notifications.
        //  - Event notifications (event_created / event_started /
        //    event_completed): scoped to NULL + Tax Program Corp + the
        //    EVENT'S TARGET CORP. Events with corporation_id=null go admin-
        //    only (NULL + tax program). Corp-specific events additionally
        //    reach that corp's webhook. Parallel to the tax-routing model.
        //  - Everything else (broadcast tax_*, moon_*, theft_*): scoped to
        //    the Tax Program / Moon Owner Corporation only.
        $eventTypesThatAreGlobal = ['report_generated'];
        $individualTaxEventTypes = ['tax_reminder', 'tax_invoice', 'tax_overdue'];
        $eventDispatchTypes = ['event_created', 'event_started', 'event_completed'];
        $structureAlertTypes = ['extraction_at_risk', 'extraction_lost'];

        if (in_array($eventType, $eventTypesThatAreGlobal, true)) {
            $webhooks = \MiningManager\Models\WebhookConfiguration::enabled()
                ->forEvent($eventType)
                ->get();
        } elseif (in_array($eventType, $individualTaxEventTypes, true)) {
            // Individual tax notifications — include the miner's corp in the
            // scope filter if available. The wrapper methods (sendTaxReminder,
            // sendTaxInvoice, sendTaxOverdue) resolve the miner's current corp
            // via character_affiliations and inject miner_corporation_id.
            $minerCorpId = isset($data['miner_corporation_id']) && $data['miner_corporation_id']
                ? (int) $data['miner_corporation_id']
                : null;
            $webhooks = $this->getMoonOwnerScopedWebhooks($eventType, $minerCorpId);
        } elseif (in_array($eventType, $eventDispatchTypes, true)) {
            // Event notifications — include the event's target corp in the
            // scope filter if the event has one. sendEventCreated/Started/
            // Completed inject event_corporation_id from the MiningEvent model.
            // Events with corporation_id=null degrade to admin-only scope.
            $eventCorpId = isset($data['event_corporation_id']) && $data['event_corporation_id']
                ? (int) $data['event_corporation_id']
                : null;
            $webhooks = $this->getMoonOwnerScopedWebhooks($eventType, $eventCorpId);
        } elseif (in_array($eventType, $structureAlertTypes, true)) {
            // Structure alert notifications (extraction_at_risk, extraction_lost)
            // — include the STRUCTURE OWNER corp in the scope filter. The
            // StructureAlertHandler injects structure_corporation_id from the
            // Structure Manager EventBus payload. Parallel to event-dispatch
            // scoping: admin webhooks (NULL) + moon owner corp + structure
            // owner corp all receive.
            $structureCorpId = isset($data['structure_corporation_id']) && $data['structure_corporation_id']
                ? (int) $data['structure_corporation_id']
                : null;
            $webhooks = $this->getMoonOwnerScopedWebhooks($eventType, $structureCorpId);
        } else {
            $webhooks = $this->getMoonOwnerScopedWebhooks($eventType);
        }

        if ($webhooks->isEmpty()) {
            Log::info("NotificationService: No webhooks matched for event '{$eventType}' (check subscriptions + corporation_id scope)");
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

                    // Role mention — per-type settings authoritative. The shared
                    // trait helper handles the ping_role OFF case + per-type
                    // role vs webhook legacy fallback precedence.
                    $roleMention = $this->getDiscordRoleMention($eventType, $webhook);
                    if ($roleMention) {
                        $message['content'] = !empty($message['content'])
                            ? $roleMention . ' ' . $message['content']
                            : $roleMention;
                    }

                    if ($webhook->discord_username) {
                        $message['username'] = $webhook->discord_username;
                    }
                    // No avatar_url override — Discord's own webhook UI
                    // is the canonical place to set the avatar, so we
                    // don't duplicate it as a per-MM-row field.

                    $response = $this->postWithRetry($webhook->webhook_url, $message);
                    $sent = $response->successful() || $response->status() === 204;

                    if (!$sent) {
                        $error = "Discord returned status {$response->status()}: {$response->body()}";
                        $webhook->recordFailure($error);
                        $results['failed'][] = ['webhook_id' => $webhook->id, 'error' => $error];
                    }
                } elseif ($webhook->type === 'slack') {
                    // Format for Slack
                    $message = $this->formatMessageForSlack($type, $data);
                    $response = $this->postWithRetry($webhook->webhook_url, $message);
                    $sent = $response->successful();

                    if (!$sent) {
                        $error = "Slack returned status {$response->status()}: {$response->body()}";
                        $webhook->recordFailure($error);
                        $results['failed'][] = ['webhook_id' => $webhook->id, 'error' => $error];
                    }
                } elseif ($webhook->type === 'custom') {
                    // Custom webhooks: if a per-webhook `custom_payload_template`
                    // is set, do variable substitution into that template string;
                    // otherwise emit the type-specific default shape from
                    // formatCustomPayload(). Both branches honour per-webhook
                    // `custom_headers` if provided. No retry — custom endpoints
                    // vary wildly, and the old behaviour pre-Phase A also had
                    // no retry on the custom path.
                    if ($webhook->custom_payload_template) {
                        $payload = $this->processCustomTemplate($webhook->custom_payload_template, $type, $eventType, $data);
                    } else {
                        $payload = $this->formatCustomPayload($type, $eventType, $data);
                    }

                    $request = Http::timeout(10);
                    if ($webhook->custom_headers && is_array($webhook->custom_headers)) {
                        foreach ($webhook->custom_headers as $key => $value) {
                            $request = $request->withHeader($key, $value);
                        }
                    }

                    $response = $request->post($webhook->webhook_url, $payload);
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
    /**
     * Build ESI-mail subject + body for the given notification type.
     *
     * Public so the diagnostic preview UI can call it directly rather
     * than via reflection. No side effects — pure formatter.
     */
    public function formatMessageForESI(string $type, array $data): array
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
            self::TYPE_JACKPOT_DETECTED => [
                'subject' => sprintf('JACKPOT MOON DETECTED: %s', $data['moon_name'] ?? 'Unknown Moon'),
                'body' => sprintf(
                    "A jackpot moon extraction has been detected!\n\n" .
                    "Moon: %s\nStructure: %s\nSystem: %s\n" .
                    "Detected By: %s\nEstimated Value (with 2x jackpot multiplier): %s ISK\n\n" .
                    "%s\n\n" .
                    "Mine this chunk before it goes unstable!\n\n" .
                    "%s Management",
                    $data['moon_name'] ?? 'Unknown',
                    $data['structure_name'] ?? 'Unknown Structure',
                    $data['system_name'] ?? 'Unknown System',
                    $data['detected_by'] ?? 'System',
                    isset($data['estimated_value']) ? number_format((float) $data['estimated_value'], 0) : 'Unknown',
                    !empty($data['ore_summary']) ? "Ore Composition:\n{$data['ore_summary']}" : '',
                    $this->getCorpName()
                )
            ],
            self::TYPE_MOON_CHUNK_UNSTABLE => [
                'subject' => sprintf('Moon Chunk Going Unstable: %s', $data['moon_name'] ?? 'Unknown Moon'),
                'body' => sprintf(
                    "WARNING: A moon chunk is approaching its unstable state.\n\n" .
                    "Moon: %s\nStructure: %s\nGoes Unstable: %s\nTime Remaining: %s\n\n" .
                    "Capital pilots should dock up before the chunk goes unstable — hostile " .
                    "activity often spikes during this window.\n\n" .
                    "%s Management",
                    $data['moon_name'] ?? 'Unknown',
                    $data['structure_name'] ?? 'Unknown Structure',
                    $data['natural_decay_time'] ?? 'Unknown',
                    $data['time_until_unstable'] ?? 'Unknown',
                    $this->getCorpName()
                )
            ],
            self::TYPE_EXTRACTION_AT_RISK => [
                'subject' => sprintf(
                    'EXTRACTION IN DANGER (%s): %s',
                    isset($data['alert_flavor']) ? ucwords(str_replace('_', ' ', $data['alert_flavor'])) : 'Threat',
                    $data['moon_name'] ?? 'Unknown Moon'
                ),
                'body' => sprintf(
                    "An active moon extraction is at risk.\n\n" .
                    "Threat: %s\nMoon: %s\nStructure: %s\nSystem: %s\n%s%s%s%s\n" .
                    "Chunk Value: %s ISK\n\n" .
                    "%s Management",
                    isset($data['alert_flavor']) ? ucwords(str_replace('_', ' ', $data['alert_flavor'])) : 'Unknown',
                    $data['moon_name'] ?? 'Unknown',
                    $data['structure_name'] ?? 'Unknown Structure',
                    $data['system_name'] ?? 'Unknown System',
                    isset($data['days_remaining']) ? "Fuel Remaining: " . number_format((float) $data['days_remaining'], 1) . " days\n" : '',
                    isset($data['fuel_expires']) ? "Fuel Expires: {$data['fuel_expires']}\n" : '',
                    isset($data['timer_ends_at']) ? "Timer Ends: {$data['timer_ends_at']}\n" : '',
                    !empty($data['attacker_summary']) ? "Hostile Force: {$data['attacker_summary']}\n" : '',
                    isset($data['estimated_value']) ? number_format((float) $data['estimated_value'], 0) : 'Unknown',
                    $this->getCorpName()
                )
            ],
            self::TYPE_EXTRACTION_LOST => [
                'subject' => sprintf('MOON CHUNK DESTROYED: %s', $data['moon_name'] ?? 'Unknown Moon'),
                'body' => sprintf(
                    "A moon extraction structure has been destroyed.\n\n" .
                    "Moon: %s\nStructure: %s\nSystem: %s\nDestroyed: %s\n" .
                    "Outcome: %s\nChunk Value Lost: %s ISK\n%s%s\n" .
                    "%s Management",
                    $data['moon_name'] ?? 'Unknown',
                    $data['structure_name'] ?? 'Unknown Structure',
                    $data['system_name'] ?? 'Unknown System',
                    $data['destroyed_at'] ?? 'Unknown',
                    $data['final_timer_result'] ?? 'Unknown',
                    isset($data['chunk_value']) ? number_format((float) $data['chunk_value'], 0) : 'Unknown',
                    !empty($data['attacker_summary']) ? "Destroyed By: {$data['attacker_summary']}\n" : '',
                    !empty($data['killmail_url']) ? "Killmail: {$data['killmail_url']}\n" : '',
                    $this->getCorpName()
                )
            ],
            self::TYPE_THEFT_DETECTED,
            self::TYPE_CRITICAL_THEFT,
            self::TYPE_ACTIVE_THEFT,
            self::TYPE_INCIDENT_RESOLVED => [
                'subject' => sprintf(
                    '%s: %s',
                    match ($type) {
                        self::TYPE_CRITICAL_THEFT => 'CRITICAL THEFT DETECTED',
                        self::TYPE_ACTIVE_THEFT => 'ACTIVE THEFT IN PROGRESS',
                        self::TYPE_INCIDENT_RESOLVED => 'Theft Incident Resolved',
                        default => 'Theft Incident Detected',
                    },
                    $data['character_name'] ?? 'Unknown Character'
                ),
                'body' => sprintf(
                    "A theft incident has %s.\n\n" .
                    "Character: %s\nSeverity: %s\nOre Value: %s ISK\nTax Owed: %s ISK\n" .
                    "Detected: %s\n%s%s\n" .
                    "%s Management",
                    match ($type) {
                        self::TYPE_INCIDENT_RESOLVED => 'been resolved',
                        self::TYPE_CRITICAL_THEFT => 'reached critical severity',
                        self::TYPE_ACTIVE_THEFT => 'continued — character is still mining',
                        default => 'been detected',
                    },
                    $data['character_name'] ?? 'Unknown',
                    $data['severity_label'] ?? ucfirst($data['severity'] ?? 'unknown'),
                    isset($data['ore_value']) ? number_format((float) $data['ore_value'], 0) : 'Unknown',
                    isset($data['tax_owed']) ? number_format((float) $data['tax_owed'], 0) : 'Unknown',
                    $data['detected_at_display'] ?? ($data['detected_at'] ?? 'Unknown'),
                    ($type === self::TYPE_ACTIVE_THEFT && isset($data['new_mining_value']))
                        ? "New Activity Value: " . number_format((float) $data['new_mining_value'], 0) . " ISK\n"
                        : '',
                    !empty($data['incident_url']) ? "Details: {$data['incident_url']}\n" : '',
                    $this->getCorpName()
                )
            ],
            self::TYPE_REPORT_GENERATED => [
                'subject' => sprintf(
                    'Mining Report: %s',
                    $data['report_type_label'] ?? ($data['report_type'] ?? 'Generated')
                ),
                'body' => sprintf(
                    "A mining report has been generated.\n\n" .
                    "Report Type: %s\nPeriod: %s\nGenerated By: %s\n%s\n" .
                    "%s Management",
                    $data['report_type_label'] ?? ($data['report_type'] ?? 'Unknown'),
                    isset($data['period']['start'], $data['period']['end'])
                        ? "{$data['period']['start']} to {$data['period']['end']}"
                        : 'Unknown',
                    $data['generated_by'] ?? 'System',
                    !empty($data['report_url']) ? "View: {$data['report_url']}\n" : '',
                    $this->getCorpName()
                )
            ],
            self::TYPE_TAX_GENERATED => [
                'subject' => sprintf('Mining Taxes Summary: %s', $data['period_label'] ?? 'Period'),
                'body' => sprintf(
                    "Mining taxes have been calculated.\n\n" .
                    "Period: %s\nAccounts Taxed: %d\nTotal Tax: %s\nDue Date: %s\nWallet: %s\n\n" .
                    "Taxation, collection, and verification happen automatically.\n" .
                    "Monitor the Wallet Verification page for mismatched payments.\n\n" .
                    "%s Management",
                    $data['period_label'] ?? 'Unknown',
                    (int) ($data['tax_count'] ?? 0),
                    $data['formatted_amount'] ?? 'Unknown',
                    $data['due_date'] ?? 'See tax page',
                    $data['wallet_division'] ?? 'Master Wallet',
                    $this->getCorpName()
                )
            ],
            self::TYPE_TAX_ANNOUNCEMENT => [
                'subject' => sprintf('New Mining Invoices: %s', $data['period_label'] ?? 'Period'),
                'body' => sprintf(
                    "Mining tax invoices have been generated for this period.\n\n" .
                    "Period: %s\nDue Date: %s\n\n" .
                    "Check My Taxes to view your invoice and payment instructions.\n\n" .
                    "%s Management",
                    $data['period_label'] ?? 'Unknown',
                    $data['due_date'] ?? 'See tax page',
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
    /**
     * Build the Slack attachment payload for the given notification type.
     *
     * Public so the diagnostic preview UI can call it directly rather
     * than via reflection. No side effects — pure formatter.
     */
    public function formatMessageForSlack(string $type, array $data): array
    {
        $color = match ($type) {
            self::TYPE_TAX_OVERDUE => 'danger',
            self::TYPE_TAX_REMINDER => 'warning',
            self::TYPE_TAX_INVOICE => 'warning',
            self::TYPE_TAX_GENERATED => 'good',
            self::TYPE_TAX_ANNOUNCEMENT => '#3498DB',
            self::TYPE_EVENT_CREATED => '#439FE0',
            self::TYPE_EVENT_STARTED, self::TYPE_EVENT_COMPLETED => 'good',
            self::TYPE_MOON_READY => '#3498DB',
            self::TYPE_JACKPOT_DETECTED => '#FFD700',
            self::TYPE_MOON_CHUNK_UNSTABLE => '#FF6B00',
            self::TYPE_THEFT_DETECTED => '#FFA500',
            self::TYPE_CRITICAL_THEFT => 'danger',
            self::TYPE_ACTIVE_THEFT => 'danger',
            self::TYPE_INCIDENT_RESOLVED => 'good',
            self::TYPE_REPORT_GENERATED => '#3498DB',
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
            self::TYPE_MOON_READY => "Moon Chunk Ready — " . ($data['moon_name'] ?? 'Unknown Moon'),
            self::TYPE_JACKPOT_DETECTED => "⭐ JACKPOT MOON DETECTED — " . ($data['moon_name'] ?? 'Unknown Moon'),
            self::TYPE_MOON_CHUNK_UNSTABLE => "⚠️ " . ($data['moon_name'] ?? 'Moon chunk') . " going unstable in " . ($data['time_until_unstable'] ?? '~2h') . " — capital pilots prepare to dock",
            self::TYPE_THEFT_DETECTED => "Theft Incident Detected — " . ($data['character_name'] ?? 'Unknown'),
            self::TYPE_CRITICAL_THEFT => "🚨 CRITICAL THEFT — " . ($data['character_name'] ?? 'Unknown'),
            self::TYPE_ACTIVE_THEFT => "🔥 ACTIVE THEFT IN PROGRESS — " . ($data['character_name'] ?? 'Unknown'),
            self::TYPE_INCIDENT_RESOLVED => "Theft Incident Resolved — " . ($data['character_name'] ?? 'Unknown'),
            self::TYPE_REPORT_GENERATED => "📊 Mining Report Generated — " . ucfirst($data['report_type'] ?? 'report') . " for " . ($data['period_str'] ?? 'current period'),
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
    /**
     * Build the JSON payload for a generic (custom) webhook.
     *
     * Some notification types have bespoke top-level shapes that downstream
     * consumers depend on (reports, moon events, theft events); others fall
     * through to a simple {event_type, notification_type, data, timestamp}
     * envelope. Keeps custom-webhook behaviour wire-compatible with what the
     * old WebhookService used to emit before consolidation.
     *
     * @param string $type Notification type constant (self::TYPE_*)
     * @param string $eventType Webhook subscription column key
     * @param array  $data Notification data
     * @return array Payload to POST as JSON
     */
    /**
     * Process a per-webhook custom JSON payload template, substituting
     * `{{variable_name}}` placeholders from the notification $data.
     *
     * Used for custom webhooks that have a `custom_payload_template` string
     * configured — typically to route notifications to a third-party bot or
     * a proprietary JSON API with a specific schema. When the template can't
     * be parsed as JSON after substitution, returns an empty array (matches
     * the pre-Phase D behaviour of the old WebhookService::processCustomTemplate).
     *
     * @param string $template Raw JSON template with {{placeholder}} tokens
     * @param string $type     Notification type constant
     * @param string $eventType Webhook event-column key
     * @param array  $data Notification data used for substitution
     * @return array Parsed JSON payload (or empty array on parse failure)
     */
    protected function processCustomTemplate(string $template, string $type, string $eventType, array $data): array
    {
        $vars = array_merge([
            'event_type' => $eventType,
            'notification_type' => $type,
            'timestamp' => now()->toIso8601String(),
        ], $data);

        $processed = $template;
        foreach ($vars as $key => $value) {
            if (is_scalar($value) || $value === null) {
                // JSON-escape every substitution rather than raw cast-to-string.
                //
                // Pre-fix `(string) $value` substitution had two bugs:
                //   1. Injection: a string value containing `"` or `\` or
                //      a newline broke the surrounding template's JSON
                //      (parsed back as null → notification silently dropped).
                //      Worse, an attacker-controlled string field
                //      (attacker_corporation_name from a hostile structure
                //      attacker, or a player-set character/corp/structure
                //      name with a `"` in it) could craft a payload that
                //      injected extra JSON keys into the templated output
                //      (`Bob", "admin": true, "x": "` → extra admin:true
                //      key in the resulting body).
                //   2. Null/bool malformedness: `(string) null` is `''`,
                //      `(string) true` is `'1'`, `(string) false` is `''`.
                //      A template like `{"count": {{count}}}` where count
                //      is null produced `{"count": }` — invalid JSON,
                //      whole notification dropped.
                //
                // Strategy:
                //   - For strings: json_encode produces `"escaped string"`.
                //     Strip exactly one leading + one trailing quote so the
                //     substitution remains a drop-in replacement inside
                //     a quoted template context like `"name": "{{var}}"`.
                //   - For numbers/booleans: json_encode produces the bare
                //     literal (`42`, `true`, `false`) — correct for raw
                //     contexts like `"count": {{count}}` AND OK inside
                //     quoted contexts (Discord/Slack accept "true"/"42").
                //   - For null: produces the literal `null`, which is
                //     correct in either context.
                //
                // Backward-compat note: a customer template that previously
                // relied on `(string) true → '1'` will now substitute
                // `true` instead. This is more semantically correct (JSON
                // boolean) and matches what every JSON consumer expects.
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($value) && strlen($encoded) >= 2) {
                    // Strip the surrounding quotes json_encode adds for strings.
                    // substr(..., 1, -1) is precise: one off each end.
                    $encoded = substr($encoded, 1, -1);
                }
                $processed = str_replace('{{' . $key . '}}', $encoded, $processed);
            } elseif (is_array($value) || is_object($value)) {
                // Substitute the full JSON literal for array/object values.
                //
                // Pre-fix the loop's `is_scalar || === null` filter dropped
                // these silently — the placeholder literal stayed in the
                // output, breaking JSON parsing for templates that had
                // intentional object/array placeholders like:
                //
                //   {"data": {{raw_summary}}, "taxes": {{raw_taxes}}}
                //
                // (Both `raw_summary` and `raw_taxes` flow through
                // formatCustomPayload as arrays — see TYPE_REPORT_GENERATED
                // shape above. A custom template using these placeholders
                // would silently drop the notification on every fire.)
                //
                // Quote-stripping does NOT apply here: arrays and objects
                // produce literals like `[1,2,3]` or `{"a":1}` that are
                // already syntactically complete JSON values. They go
                // straight into raw-context positions like
                // `"taxes": {{raw_taxes}}` (no surrounding quotes in the
                // template). Wrapping a placeholder in quotes for an array
                // value (e.g. `"name": "{{some_array}}"`) was already
                // pre-fix not supported and isn't sensible — the template
                // author should pick scalar fields for string contexts.
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $processed = str_replace('{{' . $key . '}}', $encoded, $processed);
            }
            // Resources, closures, and other non-JSON-encodable values fall
            // through with no substitution. The placeholder literal stays
            // in the output (same as pre-fix behaviour for these — they
            // shouldn't appear in $data anyway).
        }

        $decoded = json_decode($processed, true);

        // Surface JSON parse errors so operators don't silently get an
        // empty body delivered to their endpoint (looks like "the
        // notification fired but my server got nothing"). Pre-fix the
        // `?? []` swallowed every parse failure with no log line.
        // Common causes: a substituted value containing characters that
        // happen to break the surrounding template's JSON (the H3 fix
        // covers most of these; this is the catch-all for the rest),
        // or a malformed template (trailing comma, unbalanced brace).
        if ($decoded === null && trim($processed) !== '' && trim($processed) !== 'null') {
            $jsonError = function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown';
            Log::warning('Mining Manager: custom-webhook template produced unparseable JSON; payload dropped', [
                'type' => $type,
                'event_type' => $eventType,
                'json_error' => $jsonError,
                'template_length' => strlen($template),
                'processed_preview' => substr($processed, 0, 500),
            ]);
        }

        return $decoded ?? [];
    }

    protected function formatCustomPayload(string $type, string $eventType, array $data): array
    {
        $timestamp = now()->toIso8601String();

        return match ($type) {
            // Envelope wins on key collision. Pre-fix the array_merge order
            // had `raw_report_data` LAST, so a report_data payload that
            // happened to contain a key like `event_type` or `timestamp`
            // would silently override the canonical envelope value.
            // Subscribers downstream that key off `event_type` would then
            // see "report_data_some_value" instead of "report_generated".
            // Reversed the merge order — envelope keys are now authoritative.
            self::TYPE_REPORT_GENERATED => array_merge($data['raw_report_data'] ?? [], [
                'event_type' => 'report_generated',
                'timestamp' => $timestamp,
                'report' => [
                    'id' => $data['report_id'] ?? null,
                    'report_type' => $data['report_type'] ?? null,
                    'start_date' => $data['period']['start'] ?? null,
                    'end_date' => $data['period']['end'] ?? null,
                    'format' => $data['format'] ?? null,
                    'generated_at' => $data['generated_at_iso'] ?? $timestamp,
                    'generated_by' => $data['generated_by'] ?? null,
                ],
                'summary' => $data['raw_summary'] ?? [],
                'taxes' => $data['raw_taxes'] ?? [],
            ]),

            // Moon notifications — previous behaviour was array_merge of a
            // {event_type, timestamp} envelope with the raw $data. Preserve
            // byte-for-byte by doing the same shape here. The unstable-chunk
            // warning uses the same flat shape since it carries the same
            // moon/structure/timing fields.
            self::TYPE_MOON_READY,
            self::TYPE_JACKPOT_DETECTED,
            self::TYPE_MOON_CHUNK_UNSTABLE => array_merge([
                'event_type' => $eventType,
                'timestamp' => $timestamp,
            ], $data),

            // Theft notifications — previous behaviour nested the incident
            // as an object under `incident`, merged any additional_data keys
            // at the top level. Preserved byte-for-byte.
            self::TYPE_THEFT_DETECTED,
            self::TYPE_CRITICAL_THEFT,
            self::TYPE_ACTIVE_THEFT,
            self::TYPE_INCIDENT_RESOLVED => array_merge([
                'event_type' => $eventType,
                'incident' => [
                    'id' => $data['incident_id'] ?? null,
                    'character_id' => $data['character_id'] ?? null,
                    'character_name' => $data['character_name'] ?? null,
                    'severity' => $data['severity'] ?? null,
                    'ore_value' => $data['ore_value'] ?? null,
                    'tax_owed' => $data['tax_owed'] ?? null,
                    'status' => $data['status'] ?? null,
                    'detected_at' => $data['detected_at_iso'] ?? $timestamp,
                ],
                'timestamp' => $timestamp,
            ], $data['_additional_data'] ?? []),

            default => [
                'event_type' => $eventType,
                'notification_type' => $type,
                'data' => $data,
                'timestamp' => $timestamp,
            ],
        };
    }

    /**
     * Resolve the Discord embed color for an extraction_at_risk alert.
     *
     * Two-stage resolution:
     *   1. First check the envelope's severity field ('info'|'warning'|'critical').
     *      SM controls this and may upgrade severity in edge cases (e.g. mark a
     *      shield_reinforced as 'critical' when the timer ends in <30min).
     *      Severity is the operator-meaningful tier; we honour it when present.
     *   2. Fall back to flavor-based defaults — preserves the original color
     *      mapping so alerts published by older SM (or third-party publishers)
     *      that omit severity still render with sensible per-flavor colors.
     *
     * Per-flavor defaults:
     *   fuel_critical       → 0x992D22 dark red (ops must act, not combat)
     *   shield_reinforced   → 0xE67E22 orange (early warning)
     *   armor_reinforced    → 0xE74C3C red (strategic timer)
     *   hull_reinforced     → 0x992D22 dark red (final stand)
     *
     * Per-severity overrides (only applied when severity is present in payload):
     *   info     → 0x3498DB blue (informational, e.g. fuel_recovered all-clear)
     *   warning  → flavor default (the bulk of alerts)
     *   critical → 0xFF0000 hard red (escalation — overrides flavor color)
     *
     * @param array $data
     * @return int Discord color int
     */
    protected function resolveExtractionAtRiskColor(array $data): int
    {
        // Severity overrides flavor when explicitly present and non-default.
        $severity = $data['severity'] ?? null;
        if ($severity === 'critical') {
            return 0xFF0000; // hard red — operator must act NOW
        }
        if ($severity === 'info') {
            return 0x3498DB; // calm blue — non-urgent state-change ping
        }

        // 'warning' (or missing) falls through to per-flavor defaults.
        return match ($data['alert_flavor'] ?? 'generic') {
            'fuel_critical' => 0x992D22,      // dark red
            'shield_reinforced' => 0xE67E22,  // orange
            'armor_reinforced' => 0xE74C3C,   // red
            'hull_reinforced' => 0x992D22,    // dark red
            default => 0xE67E22,              // orange fallback
        };
    }

    /**
     * Resolve the Discord embed title for an extraction_at_risk alert.
     * Urgent, dramatic language by design — these are high-signal events
     * a defense FC needs to spot in a noisy channel.
     *
     * @param array $data
     * @return string
     */
    protected function resolveExtractionAtRiskTitle(array $data): string
    {
        return match ($data['alert_flavor'] ?? 'generic') {
            'fuel_critical' => '🔥 MOON CHUNK COMPROMISED — Fuel Critical',
            'shield_reinforced' => '⚠️ EXTRACTION IN DANGER — Shield Down',
            'armor_reinforced' => '🚨 EXTRACTION IN DANGER — Armor Timer',
            'hull_reinforced' => '💀 MOON CHUNK DESTABILISED — Final Timer',
            default => '⚠️ EXTRACTION IN DANGER',
        };
    }

    /**
     * Build the Discord embed payload for the given notification type.
     *
     * Public so the diagnostic preview UI can call it directly rather
     * than via reflection. No side effects — pure formatter.
     */
    public function formatMessageForDiscord(string $type, array $data): array
    {
        $color = match ($type) {
            self::TYPE_TAX_OVERDUE => 15158332, // Red
            self::TYPE_TAX_REMINDER => 16776960, // Yellow
            self::TYPE_TAX_INVOICE => 16776960, // Yellow (action required, same as reminder)
            self::TYPE_TAX_GENERATED => 3447003, // Teal
            self::TYPE_TAX_ANNOUNCEMENT => 4437216, // Blue
            self::TYPE_EVENT_CREATED => 4437216, // Blue
            self::TYPE_EVENT_STARTED, self::TYPE_EVENT_COMPLETED => 3066993, // Green
            self::TYPE_MOON_READY => 0x3498DB, // Blue
            self::TYPE_JACKPOT_DETECTED => 0xFFD700, // Gold
            self::TYPE_MOON_CHUNK_UNSTABLE => 0xFF6B00, // Orange-red (capital-safety warning)
            // Extraction at-risk color depends on flavor — fuel = dark red,
            // shield = orange, armor = red, hull = dark red. Resolved by helper.
            self::TYPE_EXTRACTION_AT_RISK => $this->resolveExtractionAtRiskColor($data),
            self::TYPE_EXTRACTION_LOST => 0x1F0000, // Near-black / very dark red — post-mortem
            self::TYPE_THEFT_DETECTED => 0xFFA500, // Orange
            self::TYPE_CRITICAL_THEFT => 0xFF0000, // Red
            self::TYPE_ACTIVE_THEFT => 0xFF6B00, // Orange-red
            self::TYPE_INCIDENT_RESOLVED => 0x00FF00, // Green
            self::TYPE_REPORT_GENERATED => 0x3498DB, // Blue
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
            self::TYPE_MOON_READY => 'Moon Chunk Ready',
            self::TYPE_JACKPOT_DETECTED => 'JACKPOT MOON DETECTED!',
            self::TYPE_MOON_CHUNK_UNSTABLE => '⚠️ Moon Chunk Going Unstable — Dock Capitals',
            // Extraction at-risk title depends on flavor — see helper.
            self::TYPE_EXTRACTION_AT_RISK => $this->resolveExtractionAtRiskTitle($data),
            self::TYPE_EXTRACTION_LOST => '☠️ MOON CHUNK DESTROYED',
            self::TYPE_THEFT_DETECTED => 'Theft Incident Detected',
            self::TYPE_CRITICAL_THEFT => 'CRITICAL THEFT DETECTED',
            self::TYPE_ACTIVE_THEFT => 'ACTIVE THEFT IN PROGRESS',
            self::TYPE_INCIDENT_RESOLVED => 'Theft Incident Resolved',
            self::TYPE_REPORT_GENERATED => '📊 Mining Report Generated',
            default => '📢 Mining Manager Notification'
        };

        $embed = [
            'title' => $title,
            'color' => $color,
            'fields' => $this->formatFieldsForDiscord($type, $data),
            'footer' => [
                'text' => $this->getCorpName() . ' Mining Manager' . ($data['footer_extra'] ?? '')
            ],
            'timestamp' => Carbon::now()->toIso8601String()
        ];

        // Optional description — reports use this to distinguish estimated vs
        // finalised values; other surfaces may start using it too.
        if (!empty($data['description'])) {
            $embed['description'] = $data['description'];
        }

        return ['embeds' => [$embed]];
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
            self::TYPE_REPORT_GENERATED => array_values(array_filter([
                ['title' => 'Report Type', 'value' => ucfirst($data['report_type'] ?? 'monthly'), 'short' => true],
                ['title' => 'Period', 'value' => $data['period_str'] ?? 'N/A', 'short' => true],
                ['title' => 'Total Miners', 'value' => number_format($data['unique_miners'] ?? 0), 'short' => true],
                ['title' => 'Total Value Mined', 'value' => number_format($data['total_value'] ?? 0, 0) . ' ISK', 'short' => true],
                (!empty($data['estimated_tax']) && $data['estimated_tax'] > 0) ? [
                    'title' => ($data['is_current_month'] ?? false) ? 'Estimated Tax' : 'Total Tax',
                    'value' => number_format($data['estimated_tax'], 0) . ' ISK',
                    'short' => true,
                ] : null,
                (empty($data['is_current_month']) && !empty($data['collection_rate']))
                    ? ['title' => 'Collection Rate', 'value' => number_format($data['collection_rate'], 1) . '%', 'short' => true]
                    : null,
            ])),
            self::TYPE_MOON_READY => array_values(array_filter([
                isset($data['moon_name']) ? ['title' => 'Moon', 'value' => $data['moon_name'], 'short' => true] : null,
                isset($data['structure_name']) ? ['title' => 'Structure', 'value' => $data['structure_name'], 'short' => true] : null,
                isset($data['chunk_arrival_time']) ? ['title' => 'Arrived', 'value' => $data['chunk_arrival_time'], 'short' => true] : null,
                isset($data['auto_fracture_time']) ? ['title' => 'Est. Auto Fracture', 'value' => $data['auto_fracture_time'], 'short' => true] : null,
                (isset($data['estimated_value']) && $data['estimated_value'] > 0)
                    ? ['title' => 'Value', 'value' => number_format($data['estimated_value'], 0) . ' ISK', 'short' => true]
                    : null,
                !empty($data['extraction_url']) ? ['title' => 'Details', 'value' => '<' . $data['extraction_url'] . '|View Extraction>', 'short' => true] : null,
            ])),
            self::TYPE_JACKPOT_DETECTED => array_values(array_filter([
                isset($data['moon_name']) ? ['title' => 'Moon', 'value' => $data['moon_name'], 'short' => true] : null,
                isset($data['structure_name']) ? ['title' => 'Structure', 'value' => $data['structure_name'], 'short' => true] : null,
                isset($data['detected_by']) ? ['title' => 'Detected By', 'value' => $data['detected_by'], 'short' => true] : null,
                (isset($data['estimated_value']) && $data['estimated_value'] > 0)
                    ? ['title' => 'Estimated Value (Jackpot)', 'value' => number_format($data['estimated_value'], 0) . ' ISK', 'short' => true]
                    : null,
                !empty($data['ore_summary']) ? ['title' => 'Ore Composition', 'value' => $data['ore_summary'], 'short' => false] : null,
                !empty($data['extraction_url']) ? ['title' => 'Details', 'value' => '<' . $data['extraction_url'] . '|View Extraction>', 'short' => false] : null,
            ])),
            self::TYPE_MOON_CHUNK_UNSTABLE => array_values(array_filter([
                isset($data['moon_name']) ? ['title' => 'Moon', 'value' => $data['moon_name'], 'short' => true] : null,
                isset($data['structure_name']) ? ['title' => 'Structure', 'value' => $data['structure_name'], 'short' => true] : null,
                isset($data['natural_decay_time']) ? ['title' => 'Goes Unstable', 'value' => $data['natural_decay_time'], 'short' => true] : null,
                isset($data['time_until_unstable']) ? ['title' => 'Time Remaining', 'value' => $data['time_until_unstable'], 'short' => true] : null,
                !empty($data['extraction_url']) ? ['title' => 'Details', 'value' => '<' . $data['extraction_url'] . '|View Extraction>', 'short' => false] : null,
            ])),
            self::TYPE_EXTRACTION_AT_RISK => array_values(array_filter([
                isset($data['alert_flavor']) ? ['title' => 'Threat Type', 'value' => ucwords(str_replace('_', ' ', $data['alert_flavor'])), 'short' => true] : null,
                isset($data['moon_name']) ? ['title' => 'Moon', 'value' => $data['moon_name'], 'short' => true] : null,
                isset($data['structure_name']) ? ['title' => 'Structure', 'value' => $data['structure_name'], 'short' => true] : null,
                isset($data['system_name']) ? ['title' => 'System', 'value' => $data['system_name'], 'short' => true] : null,
                // Fuel-flavor specifics
                (($data['alert_flavor'] ?? null) === 'fuel_critical' && isset($data['days_remaining']))
                    ? ['title' => 'Fuel Remaining', 'value' => number_format((float) $data['days_remaining'], 1) . ' days', 'short' => true] : null,
                (($data['alert_flavor'] ?? null) === 'fuel_critical' && isset($data['fuel_expires']))
                    ? ['title' => 'Fuel Expires', 'value' => $data['fuel_expires'], 'short' => true] : null,
                // Reinforcement-flavor specifics
                (in_array($data['alert_flavor'] ?? null, ['shield_reinforced','armor_reinforced','hull_reinforced'], true) && isset($data['timer_ends_at']))
                    ? ['title' => 'Timer Ends', 'value' => $data['timer_ends_at'], 'short' => true] : null,
                // Hostile force — only for tactical flavors. Prefer attacker_summary
                // (richest), fall back to bare attacker_corporation_name. Skipped
                // entirely on fuel_critical and when SM has no attacker info.
                (in_array($data['alert_flavor'] ?? null, ['shield_reinforced','armor_reinforced','hull_reinforced'], true)
                    && (!empty($data['attacker_summary']) || !empty($data['attacker_corporation_name'])))
                    ? ['title' => 'Hostile Force', 'value' => $data['attacker_summary'] ?? $data['attacker_corporation_name'], 'short' => true] : null,
                // Common
                (isset($data['estimated_value']) && $data['estimated_value'] > 0)
                    ? ['title' => 'Chunk Value', 'value' => number_format((float) $data['estimated_value'], 0) . ' ISK', 'short' => true] : null,
                !empty($data['extraction_url']) ? ['title' => 'Details', 'value' => '<' . $data['extraction_url'] . '|View Extraction>', 'short' => false] : null,
                // Cross-plugin pivot — Structure Board deeplink (only when SM
                // supplied a url; older SM publishers will still have all the
                // other fields).
                !empty($data['structure_board_url']) ? ['title' => 'Structure Board', 'value' => '<' . $data['structure_board_url'] . '|Open in Structure Manager>', 'short' => false] : null,
            ])),
            self::TYPE_EXTRACTION_LOST => array_values(array_filter([
                isset($data['moon_name']) ? ['title' => 'Moon', 'value' => $data['moon_name'], 'short' => true] : null,
                isset($data['structure_name']) ? ['title' => 'Structure', 'value' => $data['structure_name'], 'short' => true] : null,
                isset($data['system_name']) ? ['title' => 'System', 'value' => $data['system_name'], 'short' => true] : null,
                isset($data['destroyed_at']) ? ['title' => 'Destroyed At', 'value' => $data['destroyed_at'], 'short' => true] : null,
                isset($data['final_timer_result']) ? ['title' => 'Outcome', 'value' => $data['final_timer_result'], 'short' => true] : null,
                (isset($data['chunk_value']) && $data['chunk_value'] > 0)
                    ? ['title' => 'Chunk Value Lost', 'value' => number_format((float) $data['chunk_value'], 0) . ' ISK', 'short' => true] : null,
                isset($data['detection_source']) ? ['title' => 'Detected Via', 'value' => $data['detection_source'], 'short' => true] : null,
                // Killer attribution — pulled from killmail/destruction notification.
                (!empty($data['attacker_summary']) || !empty($data['attacker_corporation_name']))
                    ? ['title' => 'Destroyed By', 'value' => $data['attacker_summary'] ?? $data['attacker_corporation_name'], 'short' => true] : null,
                !empty($data['killmail_url']) ? ['title' => 'Killmail', 'value' => '<' . $data['killmail_url'] . '|View Killmail>', 'short' => false] : null,
                !empty($data['extraction_url']) ? ['title' => 'Extraction', 'value' => '<' . $data['extraction_url'] . '|View Extraction>', 'short' => false] : null,
                // Structure Board deeplink (archival forensics — SM keeps the
                // board entry post-destruction for last-known state lookups).
                !empty($data['structure_board_url']) ? ['title' => 'Structure Board', 'value' => '<' . $data['structure_board_url'] . '|Open in Structure Manager>', 'short' => false] : null,
            ])),
            self::TYPE_THEFT_DETECTED,
            self::TYPE_CRITICAL_THEFT,
            self::TYPE_ACTIVE_THEFT,
            self::TYPE_INCIDENT_RESOLVED => array_values(array_filter([
                ['title' => 'Character', 'value' => $data['character_name'] ?? 'Unknown', 'short' => true],
                isset($data['severity_label']) ? ['title' => 'Severity', 'value' => $data['severity_label'], 'short' => true] : null,
                isset($data['ore_value']) ? ['title' => 'Ore Value', 'value' => number_format($data['ore_value'], 0) . ' ISK', 'short' => true] : null,
                isset($data['tax_owed']) ? ['title' => 'Tax Owed', 'value' => number_format($data['tax_owed'], 0) . ' ISK', 'short' => true] : null,
                ($type === self::TYPE_ACTIVE_THEFT && isset($data['new_mining_value'])) ? [
                    'title' => 'Active Theft Alert',
                    'value' => 'Character continues mining! New value: ' . number_format($data['new_mining_value'], 0) . ' ISK',
                    'short' => false,
                ] : null,
                isset($data['incident_url']) ? ['title' => 'Details', 'value' => '<' . $data['incident_url'] . '|View Incident>', 'short' => false] : null,
            ])),
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
            self::TYPE_REPORT_GENERATED => array_values(array_filter([
                ['name' => '📋 Report Type', 'value' => ucfirst($data['report_type'] ?? 'monthly'), 'inline' => true],
                ['name' => '📅 Period', 'value' => $data['period_str'] ?? 'N/A', 'inline' => true],
                ['name' => '👥 Total Miners', 'value' => number_format($data['unique_miners'] ?? 0), 'inline' => true],
                ['name' => '💰 Total Value Mined', 'value' => number_format($data['total_value'] ?? 0, 0) . ' ISK', 'inline' => true],
                (!empty($data['estimated_tax']) && $data['estimated_tax'] > 0) ? [
                    'name' => ($data['is_current_month'] ?? false) ? '📊 Estimated Tax' : '📊 Total Tax',
                    'value' => number_format($data['estimated_tax'], 0) . ' ISK',
                    'inline' => true,
                ] : null,
                (empty($data['is_current_month']) && !empty($data['total_paid']) && $data['total_paid'] > 0)
                    ? ['name' => '✅ Total Paid', 'value' => number_format($data['total_paid'], 0) . ' ISK', 'inline' => true]
                    : null,
                (empty($data['is_current_month']) && !empty($data['unpaid']) && $data['unpaid'] > 0)
                    ? ['name' => '❌ Outstanding', 'value' => number_format($data['unpaid'], 0) . ' ISK', 'inline' => true]
                    : null,
                (empty($data['is_current_month']) && isset($data['collection_rate']))
                    ? ['name' => '📈 Collection Rate', 'value' => number_format($data['collection_rate'], 1) . '%', 'inline' => true]
                    : null,
            ])),
            self::TYPE_MOON_READY => array_values(array_filter([
                isset($data['moon_name']) ? ['name' => 'Moon', 'value' => $data['moon_name'], 'inline' => true] : null,
                isset($data['structure_name']) ? ['name' => 'Structure', 'value' => $data['structure_name'], 'inline' => true] : null,
                isset($data['chunk_arrival_time']) ? ['name' => 'Chunk Arrived', 'value' => $data['chunk_arrival_time'], 'inline' => true] : null,
                isset($data['auto_fracture_time']) ? ['name' => '⏰ Est. Auto Fracture', 'value' => $data['auto_fracture_time'], 'inline' => true] : null,
                (isset($data['estimated_value']) && $data['estimated_value'] > 0)
                    ? ['name' => '💰 Estimated Value', 'value' => number_format($data['estimated_value'], 0) . ' ISK', 'inline' => true]
                    : null,
                !empty($data['ore_summary']) ? ['name' => '💎 Ore Composition', 'value' => $data['ore_summary'], 'inline' => false] : null,
                !empty($data['extraction_url']) ? ['name' => '🔗 Extraction Details', 'value' => '[View Extraction](' . $data['extraction_url'] . ')', 'inline' => false] : null,
            ])),
            self::TYPE_JACKPOT_DETECTED => array_values(array_filter([
                isset($data['moon_name']) ? ['name' => 'Moon', 'value' => $data['moon_name'], 'inline' => true] : null,
                isset($data['system_name']) ? ['name' => 'System', 'value' => $data['system_name'], 'inline' => true] : null,
                isset($data['structure_name']) ? ['name' => 'Structure', 'value' => $data['structure_name'], 'inline' => true] : null,
                isset($data['detected_by']) ? ['name' => 'Detected By', 'value' => $data['detected_by'], 'inline' => true] : null,
                isset($data['reported_by']) ? ['name' => '📝 Reported By', 'value' => $data['reported_by'], 'inline' => true] : null,
                isset($data['reported_by']) ? ['name' => '🔍 Status', 'value' => '⏳ Awaiting Verification', 'inline' => true] : null,
                (isset($data['estimated_value']) && $data['estimated_value'] > 0)
                    ? ['name' => '💰 Estimated Value (Jackpot)', 'value' => number_format($data['estimated_value'], 0) . ' ISK', 'inline' => true]
                    : null,
                !empty($data['ore_summary']) ? ['name' => '💎 Ore Composition', 'value' => $data['ore_summary'], 'inline' => false] : null,
                !empty($data['extraction_url']) ? ['name' => '🔗 Extraction Details', 'value' => '[View Extraction](' . $data['extraction_url'] . ')', 'inline' => false] : null,
            ])),
            self::TYPE_MOON_CHUNK_UNSTABLE => array_values(array_filter([
                isset($data['moon_name']) ? ['name' => '🌙 Moon', 'value' => $data['moon_name'], 'inline' => true] : null,
                isset($data['structure_name']) ? ['name' => '🏗️ Structure', 'value' => $data['structure_name'], 'inline' => true] : null,
                isset($data['natural_decay_time']) ? ['name' => '⏰ Goes Unstable', 'value' => $data['natural_decay_time'], 'inline' => true] : null,
                isset($data['time_until_unstable']) ? ['name' => '⏳ Time Remaining', 'value' => $data['time_until_unstable'], 'inline' => true] : null,
                (isset($data['estimated_value']) && $data['estimated_value'] > 0)
                    ? ['name' => '💰 Estimated Value', 'value' => number_format($data['estimated_value'], 0) . ' ISK', 'inline' => true]
                    : null,
                !empty($data['extraction_url']) ? ['name' => '🔗 Extraction Details', 'value' => '[View Extraction](' . $data['extraction_url'] . ')', 'inline' => false] : null,
            ])),
            self::TYPE_EXTRACTION_AT_RISK => array_values(array_filter([
                // Flavor indicator — important for quick triage in a noisy channel
                isset($data['alert_flavor']) ? ['name' => '🚨 Threat Type', 'value' => ucwords(str_replace('_', ' ', $data['alert_flavor'])), 'inline' => true] : null,
                isset($data['moon_name']) ? ['name' => '🌙 Moon', 'value' => $data['moon_name'], 'inline' => true] : null,
                isset($data['structure_name']) ? ['name' => '🏗️ Structure', 'value' => $data['structure_name'], 'inline' => true] : null,
                isset($data['system_name']) ? ['name' => '📍 System', 'value' => $data['system_name'], 'inline' => true] : null,
                // Fuel-flavor specifics — days remaining, fuel expires
                (($data['alert_flavor'] ?? null) === 'fuel_critical' && isset($data['days_remaining']))
                    ? ['name' => '⛽ Fuel Remaining', 'value' => number_format((float) $data['days_remaining'], 1) . ' days', 'inline' => true] : null,
                (($data['alert_flavor'] ?? null) === 'fuel_critical' && isset($data['fuel_expires']))
                    ? ['name' => '⏰ Fuel Expires', 'value' => $data['fuel_expires'], 'inline' => true] : null,
                // Reinforcement-flavor specifics — when the timer ends
                (in_array($data['alert_flavor'] ?? null, ['shield_reinforced','armor_reinforced','hull_reinforced'], true) && isset($data['timer_ends_at']))
                    ? ['name' => '⏱️ Timer Ends', 'value' => $data['timer_ends_at'], 'inline' => true] : null,
                // Hostile force context — only relevant for tactical (combat) flavors.
                // Prefer attacker_summary (richest, e.g. "Goonswarm [GOONS]") over the
                // bare attacker_corporation_name. Skipped on fuel_critical and on
                // missing data (SM may not have attacker info every time).
                (in_array($data['alert_flavor'] ?? null, ['shield_reinforced','armor_reinforced','hull_reinforced'], true)
                    && (!empty($data['attacker_summary']) || !empty($data['attacker_corporation_name'])))
                    ? ['name' => '⚔️ Hostile Force', 'value' => $data['attacker_summary'] ?? $data['attacker_corporation_name'], 'inline' => true] : null,
                // Common — chunk value at risk
                (isset($data['estimated_value']) && $data['estimated_value'] > 0)
                    ? ['name' => '💰 Chunk Value', 'value' => number_format((float) $data['estimated_value'], 0) . ' ISK', 'inline' => true] : null,
                !empty($data['extraction_url']) ? ['name' => '🔗 Extraction Details', 'value' => '[View Extraction](' . $data['extraction_url'] . ')', 'inline' => false] : null,
                // Cross-plugin pivot — Structure Board deeplink from SM. Only
                // shown if SM supplied a url (i.e. the publisher is on a recent
                // SM with the AlertEventEnvelope helper). Lets a defense FC
                // jump straight from the alert to SM's full structure context
                // (timers, fuel, reinforcement state) without searching.
                !empty($data['structure_board_url']) ? ['name' => '🛰️ Structure Board', 'value' => '[Open in Structure Manager](' . $data['structure_board_url'] . ')', 'inline' => false] : null,
            ])),
            self::TYPE_EXTRACTION_LOST => array_values(array_filter([
                isset($data['moon_name']) ? ['name' => '🌙 Moon', 'value' => $data['moon_name'], 'inline' => true] : null,
                isset($data['structure_name']) ? ['name' => '🏗️ Structure', 'value' => $data['structure_name'], 'inline' => true] : null,
                isset($data['system_name']) ? ['name' => '📍 System', 'value' => $data['system_name'], 'inline' => true] : null,
                isset($data['destroyed_at']) ? ['name' => '💥 Destroyed At', 'value' => $data['destroyed_at'], 'inline' => true] : null,
                isset($data['final_timer_result']) ? ['name' => '⚰️ Outcome', 'value' => $data['final_timer_result'], 'inline' => true] : null,
                (isset($data['chunk_value']) && $data['chunk_value'] > 0)
                    ? ['name' => '💸 Chunk Value Lost', 'value' => number_format((float) $data['chunk_value'], 0) . ' ISK', 'inline' => true] : null,
                isset($data['detection_source']) ? ['name' => '🔍 Detected Via', 'value' => $data['detection_source'], 'inline' => true] : null,
                // Killer attribution — present when SM resolved an attacker_corp
                // (typically from the killmail or the destruction notification).
                // Useful for post-mortem reporting and intel gathering.
                (!empty($data['attacker_summary']) || !empty($data['attacker_corporation_name']))
                    ? ['name' => '⚔️ Destroyed By', 'value' => $data['attacker_summary'] ?? $data['attacker_corporation_name'], 'inline' => true] : null,
                !empty($data['killmail_url']) ? ['name' => '☠️ Killmail', 'value' => '[View Killmail](' . $data['killmail_url'] . ')', 'inline' => false] : null,
                !empty($data['extraction_url']) ? ['name' => '🔗 Extraction', 'value' => '[View Extraction](' . $data['extraction_url'] . ')', 'inline' => false] : null,
                // Structure Board deeplink — even though the structure is
                // destroyed, SM keeps the board entry for archival/forensic
                // purposes (last-known fuel, timers, history). One-click pivot.
                !empty($data['structure_board_url']) ? ['name' => '🛰️ Structure Board', 'value' => '[Open in Structure Manager](' . $data['structure_board_url'] . ')', 'inline' => false] : null,
            ])),
            // All 4 theft variants share the same field set — only color, title
            // and description change per subtype. Active-theft additionally
            // emits the fire-alert + last-activity block when new_mining_value
            // is provided.
            self::TYPE_THEFT_DETECTED,
            self::TYPE_CRITICAL_THEFT,
            self::TYPE_ACTIVE_THEFT,
            self::TYPE_INCIDENT_RESOLVED => array_values(array_filter([
                ['name' => '👤 Character', 'value' => $data['character_name'] ?? 'Unknown', 'inline' => true],
                isset($data['severity_label']) ? ['name' => '⚠️ Severity', 'value' => $data['severity_label'], 'inline' => true] : null,
                isset($data['ore_value']) ? ['name' => '💰 Ore Value', 'value' => number_format($data['ore_value'], 0) . ' ISK', 'inline' => true] : null,
                isset($data['tax_owed']) ? ['name' => '📋 Tax Owed', 'value' => number_format($data['tax_owed'], 0) . ' ISK', 'inline' => true] : null,
                isset($data['detected_at_display']) ? ['name' => '📅 Detected', 'value' => $data['detected_at_display'], 'inline' => true] : null,
                isset($data['status_label']) ? ['name' => '🔍 Status', 'value' => $data['status_label'], 'inline' => true] : null,
                ($type === self::TYPE_ACTIVE_THEFT && isset($data['new_mining_value'])) ? [
                    'name' => '🔥 Active Theft Alert',
                    'value' => "Character continues mining!\nNew value: " . number_format($data['new_mining_value'], 0) . ' ISK' .
                               "\nActivity count: " . ($data['activity_count'] ?? 1),
                    'inline' => false,
                ] : null,
                ($type === self::TYPE_ACTIVE_THEFT && isset($data['last_activity'])) ? [
                    'name' => '🕐 Last Activity',
                    'value' => $data['last_activity'],
                    'inline' => false,
                ] : null,
                isset($data['incident_url']) ? [
                    'name' => '🔗 View Incident',
                    'value' => "[Click here to view details]({$data['incident_url']})",
                    'inline' => false,
                ] : null,
            ])),
            default => []
        };
    }

    /**
     * Get character refresh token from SeAT, validating it's not expired.
     *
     * Uses the SeAT-canonical `RefreshToken` Eloquent model rather than a
     * raw `DB::table('refresh_tokens')` query. Two reasons:
     *
     *   1. The model's `->token` accessor returns `null` when the access
     *      token has expired AND SeAT's refresh path failed (revoked
     *      refresh token, ESI auth outage, etc.). Pre-fix, the raw query
     *      returned the row with whatever stale `access_token` was last
     *      written — Eseye would then fail mid-mail-send with an opaque
     *      ESI error instead of MM cleanly logging "no valid token" and
     *      bailing early.
     *
     *   2. Going through the model means any future SeAT-side observer,
     *      audit hook, or schema change is honored. Raw DB queries
     *      bypass that and silently break under SeAT version drift.
     *
     * @see project memory reference_seat_v5_models.md
     *      ("RefreshToken (token returns NULL when expired)")
     *
     * @param int $characterId
     * @return \Seat\Eveapi\Models\RefreshToken|null  Valid token, or null when none exists / token expired without refresh
     */
    protected function getCharacterToken(int $characterId): ?\Seat\Eveapi\Models\RefreshToken
    {
        // Find the row at all. The whereRaw FIND_IN_SET filters to tokens
        // that include the mail-send scope (the only thing this method's
        // callers need it for). Storing scopes as a comma-separated string
        // is the SeAT v5 convention; the FIND_IN_SET predicate is the
        // canonical scope-membership test against that storage shape.
        $token = \Seat\Eveapi\Models\RefreshToken::where('character_id', $characterId)
            ->whereRaw("FIND_IN_SET('esi-mail.send_mail.v1', scopes) > 0")
            ->first();

        if (!$token) {
            return null;
        }

        // Validate the token is currently usable. RefreshToken::token
        // (a magic attribute, not a column) returns null when the access
        // token is expired AND SeAT couldn't refresh it. If either layer
        // fails, we treat this token as unusable so the caller logs
        // "no valid mail token" and falls back gracefully rather than
        // letting Eseye blow up mid-send.
        if (empty($token->token)) {
            Log::info('Mining Manager: Refresh token for character is expired or refresh failed', [
                'character_id' => $characterId,
            ]);
            return null;
        }

        return $token;
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
            || $this->hasAnyEnabledWebhook();
    }

    /**
     * Check if any enabled webhook exists in webhook_configurations,
     * regardless of type (Discord/Slack/custom) or which notify_* flags
     * it has set.
     *
     * Pre-fix this function:
     *   1. Filtered to `type = 'discord'`, so installs whose only
     *      configured webhooks were Slack or custom got back `false`
     *      and the entire CHANNEL_DISCORD dispatch path (which actually
     *      fans out to all three types via sendViaWebhooks) was
     *      skipped — silently dropping ALL per-webhook notifications.
     *   2. OR'd only 7 of ~17 `notify_*` flags (tax_reminder/invoice/
     *      overdue, event_*, moon_arrival). Missing
     *      notify_jackpot_detected, notify_moon_chunk_unstable,
     *      notify_extraction_at_risk/lost, notify_theft_*,
     *      notify_incident_resolved, notify_tax_generated,
     *      notify_tax_announcement, notify_report_generated. An
     *      operator who configured Discord webhooks ONLY for theft +
     *      structure-alerts (a perfectly reasonable security-alarm
     *      use case) got back `false` here → all their notifications
     *      silently dropped.
     *
     * The per-event filtering still happens correctly downstream:
     * `sendViaWebhooks` calls `WebhookConfiguration::enabled()
     * ->forEvent($eventType)->get()` which only returns webhooks with
     * the matching `notify_<event>` flag set. So this upstream gate
     * doesn't need to enumerate per-event flags — it just needs to
     * tell us "are there ANY enabled webhooks at all?"
     *
     * Function renamed from hasEnabledDiscordWebhooks to match the
     * actual behaviour. Old name's "Discord" implication was misleading
     * given the function gates webhook dispatch for all three types.
     *
     * @return bool
     */
    protected function hasAnyEnabledWebhook(): bool
    {
        try {
            return \MiningManager\Models\WebhookConfiguration::enabled()->exists();
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

        // CHANNEL_DISCORD here is a misnomer kept for backward compat —
        // it actually drives the per-webhook dispatch path (sendViaWebhooks)
        // which fans out to all three webhook types (discord/slack/custom)
        // based on each row's `type` column. So this gate adds the channel
        // whenever ANY enabled webhook exists, regardless of type.
        if ($this->hasAnyEnabledWebhook()) {
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
            // Cap the recipients payload at a representative sample. Pre-fix
            // we json_encoded the full list — for broadcast notifications
            // (`sendBroadcast`) that's the entire corp member list,
            // potentially hundreds-thousands of character IDs. Twelve
            // months of monthly tax_announcement broadcasts then store
            // 12×N character IDs duplicated.
            //
            // Now: store the count (always accurate) plus a sample of the
            // first 50 IDs (enough for "did the right cohort get pinged?"
            // forensic debugging without bloating the table). The logging
            // table doesn't have a schema for separate count/sample
            // columns, so we wrap the value in a small object to preserve
            // structure while bounding size.
            $recipientCount = count($recipients);
            $recipientPayload = [
                'count' => $recipientCount,
                'sample' => array_slice(array_values($recipients), 0, 50),
                'truncated' => $recipientCount > 50,
            ];

            DB::table('mining_notification_log')->insert([
                'type' => $type,
                'recipients' => json_encode($recipientPayload),
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
