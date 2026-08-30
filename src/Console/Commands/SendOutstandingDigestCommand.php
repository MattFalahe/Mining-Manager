<?php

namespace MiningManager\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningTax;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Notification\NotificationService;
use Seat\Eveapi\Models\Character\CharacterInfo;

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
                            {--dry-run : Print the digest without sending it}';

    /**
     * @var string
     */
    protected $description = 'Send directors a summary of mining tax that is still outstanding';

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

            // Everything with money still on it. 'partial' matters most here:
            // it is the state a token payment produces, and the one that used to
            // be invisible to every other notification.
            $taxes = MiningTax::whereIn('status', ['unpaid', 'overdue', 'partial'])
                ->where('amount_owed', '>', 0)
                ->get();

            $byCharacter = [];

            foreach ($taxes as $tax) {
                $owed = (float) $tax->amount_owed;
                $paid = (float) ($tax->amount_paid ?? 0);
                $outstanding = round($owed - $paid, 2);

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
                $this->info('Nothing outstanding. No digest sent.');

                return self::SUCCESS;
            }

            // Largest debt first: that is the order a director wants to work in.
            uasort($byCharacter, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);

            $names = CharacterInfo::whereIn('character_id', array_keys($byCharacter))
                ->pluck('name', 'character_id');

            $rows = [];
            foreach ($byCharacter as $characterId => $totals) {
                $percentPaid = $totals['owed'] > 0
                    ? round(($totals['paid'] / $totals['owed']) * 100, 1)
                    : 0.0;

                $rows[] = [
                    'character_id' => $characterId,
                    'character_name' => $names[$characterId] ?? "Character #{$characterId}",
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

            $this->notificationService->sendOutstandingDigest([
                'member_count' => count($rows),
                'formatted_total' => number_format($total, 0) . ' ISK',
                'rows' => $shown,
                'rows_text' => $this->formatRows($shown),
                'truncated_count' => $truncated,
                'period_label' => 'All open invoices as at ' . Carbon::now()->format('Y-m-d'),
                'tax_page_url' => $this->outstandingUrl(),
                'corporation_id' => $moonOwnerCorpId ? (int) $moonOwnerCorpId : null,
            ]);

            Log::info('Mining Manager: sent the outstanding tax digest', [
                'members' => count($rows),
                'total_outstanding' => $total,
            ]);

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
     * Deep link to the tax page already filtered to what this message is about.
     */
    protected function outstandingUrl(): string
    {
        return rtrim(config('app.url', ''), '/') . '/mining-manager/tax?status=outstanding';
    }
}
