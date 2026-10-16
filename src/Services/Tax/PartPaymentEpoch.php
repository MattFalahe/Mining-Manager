<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The part-payment cutover.
 *
 * Before it, theft detection treated a part-paid bill as paid, because only
 * unpaid and overdue bills were looked at and a part-paid bill keeps status
 * Partial however late it gets. After it, a part-paid bill counts as unpaid.
 * Bills raised before the cutover keep the old rule, so upgrading does not open
 * a batch of incidents over debts nobody was chasing.
 *
 * Same shape as ClassificationEpoch.
 */
class PartPaymentEpoch
{
    public const SETTING_KEY = 'tax.part_payment_epoch';

    /** Read once per request; the value never changes while a process is alive. */
    private static ?Carbon $cached = null;
    private static bool $resolved = false;

    /**
     * The cutover instant, or null when none is recorded.
     *
     * Null means every bill keeps the old rule. An install that somehow missed
     * the migration should behave as it did before, not suddenly treat every
     * part-paid bill it has ever raised as unpaid.
     */
    public static function get(): ?Carbon
    {
        if (self::$resolved) {
            return self::$cached;
        }

        self::$resolved = true;
        self::$cached = null;

        try {
            // Oldest row wins, so a duplicate can never move the line forward.
            $raw = DB::table('mining_manager_settings')
                ->where('key', self::SETTING_KEY)
                ->whereNull('corporation_id')
                ->orderBy('id')
                ->value('value');

            if ($raw) {
                self::$cached = Carbon::parse($raw);
            }
        } catch (\Throwable $e) {
            // Throwable rather than Exception: a malformed stored date raises an
            // Error from Carbon on some versions, and that must not stop a
            // scheduled theft scan.
            Log::warning('Mining Manager: tax.part_payment_epoch unreadable, part-paid bills keep the old theft rule', [
                'error' => $e->getMessage(),
            ]);
            self::$cached = null;
        }

        return self::$cached;
    }
}
