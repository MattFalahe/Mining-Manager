<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ore-classification cutover.
 *
 * TypeIdRegistry gained a large number of type IDs that it had never carried:
 * IV-Grade ore, the Exordium 0-Grade tier, the X-Grade families, Prismaticite,
 * the missing gas colours and the base Triglavian ores. That is a better
 * classification, but applying it backwards would silently re-rate mining that
 * members have already done and in many cases already been billed for. A gas
 * type moving out of the untaxed ore bucket and into a taxed gas bucket changes
 * what somebody owes for work finished weeks ago.
 *
 * So the new classification applies from the moment the plugin is upgraded and
 * no earlier. Ledger rows dated before the epoch keep the tax_rate they were
 * given at the time, whatever recomputes them afterwards.
 *
 * Same shape as payment.dedup_epoch, which draws the equivalent line under
 * wallet verification. See PaymentAllocationService::getDedupEpoch().
 */
class ClassificationEpoch
{
    public const SETTING_KEY = 'classification.epoch';

    /** Read once per request; the value never changes while a process is alive. */
    private static ?Carbon $cached = null;
    private static bool $resolved = false;

    /**
     * The cutover instant, or null when no epoch is recorded.
     *
     * A null epoch means the guard is inactive rather than "guard everything".
     * An install that somehow missed the migration should keep behaving as it
     * did before, not silently freeze every rate it owns.
     */
    public static function get(): ?Carbon
    {
        if (self::$resolved) {
            return self::$cached;
        }

        self::$resolved = true;
        self::$cached = null;

        try {
            // Oldest row wins. If a duplicate ever appears, the cutover stays
            // where it was rather than jumping forward and unfreezing history.
            $raw = DB::table('mining_manager_settings')
                ->where('key', self::SETTING_KEY)
                ->whereNull('corporation_id')
                ->orderBy('id')
                ->value('value');

            if ($raw) {
                self::$cached = Carbon::parse($raw);
            }
        } catch (\Throwable $e) {
            // Catch Throwable, not Exception: a malformed stored date raises an
            // Error from Carbon on some versions and that must not take down a
            // scheduled pricing run.
            Log::warning('Mining Manager: classification.epoch unreadable, cutover guard disabled', [
                'error' => $e->getMessage(),
            ]);
            self::$cached = null;
        }

        return self::$cached;
    }

    /**
     * Whether a ledger row already existed when the cutover was stamped.
     *
     * This, not the mining date, is what decides whether a row is history. A row
     * imported today that happens to describe mining from July is new data: it
     * has never been rated, never been billed, and freezing it would leave it
     * permanently at the 0 its column defaults to. Only rows that were already
     * in the table before the upgrade carry a rate worth preserving.
     *
     * A null created_at means the row predates timestamps being recorded, so it
     * is old by definition and gets frozen.
     *
     * @param mixed $createdAt
     */
    public static function existedBeforeCutover($createdAt): bool
    {
        $epoch = self::get();

        if ($epoch === null) {
            return false;
        }

        if ($createdAt === null || $createdAt === '') {
            return true;
        }

        try {
            return Carbon::parse($createdAt)->lt($epoch);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Test seam. Nothing in normal operation needs this.
     */
    public static function flush(): void
    {
        self::$cached = null;
        self::$resolved = false;
    }
}
