<?php

namespace MiningManager\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningTax;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Notification\NotificationService;

/**
 * The weekly "who still owes" summary for directors.
 *
 * Every other tax notification speaks to one member about one debt. Nothing
 * gave a director the whole picture, so an invoice that slipped past the
 * per-member notifications simply went unnoticed: no Discord on that member, a
 * delivery that failed quietly, or a gap in the matching. This is the backstop
 * that makes any of those visible without anyone having to go looking.
 *
 * Reports what is LEFT to pay, never the original charge, and shows how much of
 * each debt is already covered so a director can tell a member who has paid 95%
 * from one who sent a token 1m and stopped.
 */
class SendOutstandingDigestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mining-manager:send-outstanding-digest
                            {--limit=25 : Maximum members to name in the message}
                            {--force : Send even if the last digest was under a week ago}
                            {--dry-run : Print the digest without sending it}';

    /**
     * @var string
     */
    protected $description = 'Send directors a summary of mining tax that is still outstanding';

    /**
     * When the last digest went out. Stored as a setting rather than a column
     * because it is one timestamp for the whole install.
     */
    protected const LAST_SENT_KEY = 'payment.last_outstanding_digest_at';

    /**
     * Days between digests once one has been sent.
     */
    protected const REPEAT_DAYS = 7;

    protected NotificationService $notificationService;

    protected SettingsManagerService $settingsService;

    public function __construct(NotificationService $notificationService, SettingsManagerService $settingsService)
    {
        parent::__construct();

        $this->notificationService = $notificationService;
        $this->settingsService = $settingsService;
    }

    public function handle(): int
    {
        $lock = Cache::lock('mining-manager:send-outstanding-digest', 600);

        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');

            return self::SUCCESS;
        }

        try {
            $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

            if ($moonOwnerCorpId) {
                $this->settingsService->setActiveCorporation((int) $moonOwnerCorpId);
            }

            $limit = max(1, (int) $this->option('limit'));
            $dryRun = (bool) $this->option('dry-run');

            // Switched off on its card: nothing to work out, and the week does
            // not start, so switching it back on sends the next one on time.
            if (!$dryRun && !$this->notificationService->isTypeGloballyEnabled(NotificationService::TYPE_TAX_OUTSTANDING_DIGEST)) {
                $this->info('Outstanding Mining Tax is switched off under Settings, Notifications. No digest sent.');

                return self::SUCCESS;
            }

            // Everything with money still on it. 'partial' matters most here:
            // it is the state a token payment produces, and the one that used to
            // be invisible to every other notification.
            $taxes = MiningTax::outstanding()
                ->where('amount_owed', '>', 0)
                ->get();

            $names = app(\MiningManager\Services\Character\CharacterNames::class);

            // Only members of the corporations picked on the card, when any
            // are, by the corporation each one is in now.
            $corporations = $this->settingsService->getTypeNotificationSettings('tax_outstanding_digest')['corporations'];

            if ($corporations) {
                $names->lookUpNow($taxes->pluck('character_id')->unique()->all());
                $taxes = $taxes
                    ->filter(fn ($tax) => in_array((int) $names->info($tax->character_id)['corporation_id'], $corporations, true))
                    ->values();
            }

            $byCharacter = [];

            foreach ($taxes as $tax) {
                $owed = (float) $tax->amount_owed;
                $paid = (float) ($tax->amount_paid ?? 0);
                $outstanding = $tax->getRemainingBalance();

                if ($outstanding <= 0) {
                    continue;
                }

                $characterId = (int) $tax->character_id;

                if (!isset($byCharacter[$characterId])) {
                    $byCharacter[$characterId] = ['owed' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'invoices' => 0];
                }

                $byCharacter[$characterId]['owed'] += $owed;
                $byCharacter[$characterId]['paid'] += $paid;
                $byCharacter[$characterId]['outstanding'] += $outstanding;
                $byCharacter[$characterId]['invoices']++;
            }

            if (empty($byCharacter)) {
                // All clear. Forget when we last sent so the next cycle starts
                // from scratch: the first digest after the next due date should
                // arrive on time, not a week late because of this run.
                $this->settingsService->updateSetting(self::LAST_SENT_KEY, null);
                $this->info('Nothing outstanding. No digest sent.');

                return self::SUCCESS;
            }

            // The chain is: invoices go out, the due date passes, THEN directors
            // hear who has not paid, and again every 7 days until it is clear.
            // Nagging before anything is actually late would train people to
            // ignore it.
            $anythingLate = $taxes->contains(fn ($t) => $t->isOverdue());

            if (!$anythingLate && !$dryRun && !$this->option('force')) {
                $this->info('Nothing is past its due date yet. No digest sent.');

                return self::SUCCESS;
            }

            if (!$this->dueForSend() && !$dryRun && !$this->option('force')) {
                $this->info('A digest was already sent within the last 7 days. Skipping.');

                return self::SUCCESS;
            }

            // Largest debt first: that is the order a director wants to work in.
            uasort($byCharacter, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);

            $names->lookUpNow(array_keys($byCharacter));

            $rows = [];
            foreach ($byCharacter as $characterId => $totals) {
                $percentPaid = $totals['owed'] > 0
                    ? round(($totals['paid'] / $totals['owed']) * 100, 1)
                    : 0.0;

                $rows[] = [
                    'character_id' => $characterId,
                    'character_name' => $names->nameOrId($characterId),
                    'outstanding' => round($totals['outstanding'], 2),
                    'formatted_outstanding' => number_format($totals['outstanding'], 0) . ' ISK',
                    'percent_paid' => $percentPaid,
                    'invoice_count' => $totals['invoices'],
                ];
            }

            $total = round(array_sum(array_column($rows, 'outstanding')), 2);
            $shown = array_slice($rows, 0, $limit);
            $truncated = max(0, count($rows) - count($shown));

            $this->info(count($rows) . ' member(s) owe ' . number_format($total, 2) . ' ISK in total');

            if ($corporations) {
                $this->info('Only members of ' . $this->corporationsLabel($corporations));
            }

            if ($dryRun) {
                $this->table(
                    ['Character', 'Outstanding', '% paid', 'Invoices'],
                    array_map(fn ($r) => [
                        $r['character_name'],
                        $r['formatted_outstanding'],
                        $r['percent_paid'] . '%',
                        $r['invoice_count'],
                    ], $shown)
                );

                if ($truncated > 0) {
                    $this->line("... and {$truncated} more");
                }

                $this->warn('Dry run - nothing sent.');

                return self::SUCCESS;
            }

            $result = $this->notificationService->sendOutstandingDigest([
                'member_count' => count($rows),
                'formatted_total' => number_format($total, 0) . ' ISK',
                'rows' => $shown,
                'rows_text' => $this->formatRows($shown),
                'truncated_count' => $truncated,
                'period_label' => 'All open invoices as at ' . Carbon::now()->format('Y-m-d'),
                'corporations_label' => $corporations ? $this->corporationsLabel($corporations) : null,
                'tax_page_url' => $this->outstandingUrl(count($corporations) === 1 ? $corporations[0] : null),
                'corporation_id' => $moonOwnerCorpId ? (int) $moonOwnerCorpId : null,
            ]);

            // Nothing went out, so the week does not start.
            if (!empty($result['skipped'])) {
                $this->warn('Not sent: ' . ($result['reason'] ?? 'no channel took it') . '.');

                return self::SUCCESS;
            }

            Log::info('Mining Manager: sent the outstanding tax digest', [
                'members' => count($rows),
                'total_outstanding' => $total,
            ]);

            $this->settingsService->updateSetting(self::LAST_SENT_KEY, Carbon::now()->toDateTimeString());

            $this->info('Digest sent.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to send the outstanding digest: ' . $e->getMessage());

            Log::error('Mining Manager: outstanding digest failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * True when no digest has gone out for a week, or ever.
     *
     * Unreadable stored values are treated as "never sent" rather than
     * swallowing the digest forever on a bad write.
     */
    protected function dueForSend(): bool
    {
        $last = $this->settingsService->getSetting(self::LAST_SENT_KEY);

        if (empty($last)) {
            return true;
        }

        try {
            return Carbon::parse($last)->lte(Carbon::now()->subDays(self::REPEAT_DAYS));
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * One line per member, kept inside Discord's 1024-character field limit.
     *
     * Truncating mid-list is better than having the whole embed rejected, and
     * the message already carries a link to the full list.
     */
    protected function formatRows(array $rows): string
    {
        $lines = [];
        $length = 0;

        foreach ($rows as $row) {
            $line = sprintf(
                '%s - %s left (%s%% paid, %d invoice%s)',
                $row['character_name'],
                $row['formatted_outstanding'],
                $row['percent_paid'],
                $row['invoice_count'],
                $row['invoice_count'] === 1 ? '' : 's'
            );

            // 1024 is the hard cap; stop early enough to fit the closing note.
            if ($length + strlen($line) + 1 > 960) {
                $lines[] = '...';
                break;
            }

            $lines[] = $line;
            $length += strlen($line) + 1;
        }

        return implode("\n", $lines);
    }

    /**
     * The corporations a filtered digest covers, by name.
     *
     * @param array<int, int> $corporationIds
     */
    protected function corporationsLabel(array $corporationIds): string
    {
        $names = app(\MiningManager\Services\Character\CharacterNames::class)->corporationNames($corporationIds);
        $labels = array_map(fn ($id) => $names[$id] ?? "Corporation {$id}", $corporationIds);
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        return implode(', ', $labels);
    }

    /**
     * Deep link to the tax page already filtered to what this message is about.
     * Tax Overview filters by one corporation, so only a digest for a single
     * corporation links to it.
     */
    protected function outstandingUrl(?int $corporationId = null): string
    {
        return rtrim(config('app.url', ''), '/') . '/mining-manager/tax?status=outstanding'
            . ($corporationId ? '&corporation_id=' . $corporationId : '');
    }
}
