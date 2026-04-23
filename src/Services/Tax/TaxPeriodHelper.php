<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Configuration\SettingsManagerService;

class TaxPeriodHelper
{
    protected SettingsManagerService $settingsService;

    public function __construct(SettingsManagerService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Get the configured tax calculation period type.
     *
     * Respects a "queued switch" via tax_calculation_period_pending +
     * tax_calculation_period_effective_from. Period changes are always
     * deferred to the first of a future calendar month to avoid colliding
     * with tax rows whose unique key is (character_id, period_start):
     * biweekly H1 and monthly both target period_start = 1st of month,
     * so switching mid-month would either orphan the shorter periods or
     * silently overwrite their period_type under --force recalc. Queueing
     * to a month boundary sidesteps both failure modes.
     *
     * Legacy 'weekly' auto-heal: 'weekly' as an active period type was
     * removed in v1.0.3+ because weekly ISO periods cross calendar-month
     * boundaries (the week of Apr 27 - May 3 for example), causing
     * double-tax on cross-boundary mining AND breaking calendar-aligned
     * charts. Any install that has tax_calculation_period = 'weekly' in
     * its settings gets silently promoted to 'monthly' on the next read,
     * with a log warning. Historical mining_taxes rows with
     * period_type = 'weekly' are preserved in DB and still render via
     * MiningTax::formatted_period — no data loss, just no new weekly rows.
     *
     * If the pending effective date has arrived, this method transparently
     * promotes the pending value to active (and clears the pending slots)
     * on read. That keeps callers ignorant of the indirection — they just
     * see the currently-in-force period.
     *
     * @return string 'monthly' or 'biweekly'
     */
    public function getConfiguredPeriodType(): string
    {
        $rates = $this->settingsService->getTaxRates();
        $active = $rates['tax_calculation_period'] ?? 'monthly';
        $pending = $rates['tax_calculation_period_pending'] ?? null;
        $effectiveFrom = $rates['tax_calculation_period_effective_from'] ?? null;

        if ($pending && $effectiveFrom) {
            try {
                $effectiveDate = Carbon::parse($effectiveFrom)->startOfDay();
                if (Carbon::today()->gte($effectiveDate)) {
                    // Auto-heal if somehow a pending 'weekly' value was stored.
                    $promoted = $this->normaliseLegacyWeekly($pending);

                    $this->settingsService->updateSetting(
                        'tax_rates.tax_calculation_period',
                        $promoted,
                        'string'
                    );
                    $this->settingsService->updateSetting(
                        'tax_rates.tax_calculation_period_pending',
                        null,
                        'string'
                    );
                    $this->settingsService->updateSetting(
                        'tax_rates.tax_calculation_period_effective_from',
                        null,
                        'string'
                    );

                    Log::info(sprintf(
                        'Mining Manager: Tax calculation period promoted %s → %s (effective %s, promoted on %s)',
                        $active,
                        $promoted,
                        $effectiveDate->toDateString(),
                        Carbon::today()->toDateString()
                    ));

                    return $promoted;
                }
            } catch (\Throwable $e) {
                // Malformed effective date — fall back to active.
                Log::warning('Mining Manager: Malformed tax_calculation_period_effective_from: ' . $effectiveFrom);
            }
        }

        // Legacy auto-heal: convert any stored 'weekly' active value to 'monthly'.
        if ($active === 'weekly') {
            $this->settingsService->updateSetting(
                'tax_rates.tax_calculation_period',
                'monthly',
                'string'
            );
            Log::warning('Mining Manager: Auto-migrated tax_calculation_period from deprecated "weekly" to "monthly". Weekly tax periods were removed because ISO weeks straddle calendar-month boundaries, causing double-tax and chart-aggregation issues. Switch to biweekly via Settings → Tax Rates if you need sub-monthly granularity. Historical weekly tax rows remain visible in Tax History.');
            return 'monthly';
        }

        return $active;
    }

    /**
     * Convert deprecated 'weekly' input to 'monthly'. Other values pass through.
     *
     * Used on promotion (to catch legacy pending values) and potentially
     * anywhere a caller might hand us a raw periodType string.
     */
    private function normaliseLegacyWeekly(string $periodType): string
    {
        return $periodType === 'weekly' ? 'monthly' : $periodType;
    }

    /**
     * Return info about a queued period-type change, or null if none.
     *
     * Used by the tax pages' yellow banner to surface the upcoming switch.
     *
     * @return array{current: string, pending: string, effective_from: \Carbon\Carbon}|null
     */
    public function getPendingPeriodChange(): ?array
    {
        $rates = $this->settingsService->getTaxRates();
        $active = $rates['tax_calculation_period'] ?? 'monthly';
        $pending = $rates['tax_calculation_period_pending'] ?? null;
        $effectiveFrom = $rates['tax_calculation_period_effective_from'] ?? null;

        if (!$pending || !$effectiveFrom) {
            return null;
        }

        try {
            $effectiveDate = Carbon::parse($effectiveFrom)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }

        // Already passed — getConfiguredPeriodType() will promote on next call.
        if (Carbon::today()->gte($effectiveDate)) {
            return null;
        }

        // Sanity: pending equals active means someone queued a no-op; ignore.
        if ($pending === $active) {
            return null;
        }

        return [
            'current' => $active,
            'pending' => $pending,
            'effective_from' => $effectiveDate,
        ];
    }

    /**
     * Compute the safe effective date for a queued period change.
     *
     * Goal: let the CURRENT scheme finish calculating the month's last
     * period BEFORE the new scheme takes over. If we cut over too early,
     * the old scheme's final calc either collides with new-scheme rows
     * (biweekly H1 and monthly both target period_start = 1st of month)
     * or silently fails to fire (shouldCalculateToday returns false for
     * the new scheme on the old scheme's calc day).
     *
     * Both remaining period types (monthly and biweekly) schedule their
     * "previous-period" calc on DAY 2 of next month. Day 3 lets that
     * calc run under the old scheme, then promotes.
     *
     * The match structure is intentionally kept forward-compatible: if
     * the cron schedule in ScheduleSeeder ever shifts to day N, update
     * this to day N+1. If a new period type with a different calc day is
     * ever introduced, add a match arm.
     *
     * @return \Carbon\Carbon
     */
    public function nextSafeEffectiveDate(): Carbon
    {
        // Read the CURRENT active period, not the pending one. We're
        // deciding how long the old scheme should keep running.
        $rates = $this->settingsService->getTaxRates();
        $active = $rates['tax_calculation_period'] ?? 'monthly';

        $nextMonthStart = Carbon::today()->addMonthNoOverflow()->startOfMonth();

        return match ($active) {
            // Both monthly and biweekly fire their previous-period calc on
            // day 2 of next month. Day 3 is the first safe cutover.
            'monthly', 'biweekly' => $nextMonthStart->addDays(2),
            // Defensive fallback — unknown/legacy value, use the conservative
            // day 3 rule. (A legacy 'weekly' value is auto-healed by
            // getConfiguredPeriodType() before this method gets called.)
            default => $nextMonthStart->addDays(2),
        };
    }

    /**
     * Get the start and end dates for the period containing the given date.
     *
     * @param Carbon $date Any date within the desired period
     * @param string $periodType 'monthly' or 'biweekly' (legacy 'weekly' maps to monthly)
     * @return array [Carbon $start, Carbon $end]
     */
    public function getPeriodBounds(Carbon $date, string $periodType): array
    {
        return match ($this->normaliseLegacyWeekly($periodType)) {
            'biweekly' => $this->getBiweeklyBounds($date),
            default => $this->getMonthlyBounds($date),
        };
    }

    /**
     * Get monthly period bounds (1st to last day of month).
     */
    private function getMonthlyBounds(Carbon $date): array
    {
        return [
            $date->copy()->startOfMonth(),
            $date->copy()->endOfMonth(),
        ];
    }

    /**
     * Get biweekly period bounds.
     * First half: 1st-14th, Second half: 15th-end of month.
     */
    private function getBiweeklyBounds(Carbon $date): array
    {
        $day = (int) $date->format('d');

        if ($day <= 14) {
            return [
                $date->copy()->startOfMonth(),
                $date->copy()->startOfMonth()->addDays(13), // 14th
            ];
        }

        return [
            $date->copy()->startOfMonth()->addDays(14), // 15th
            $date->copy()->endOfMonth(),
        ];
    }

    /**
     * Get the most recently completed period.
     *
     * @param string $periodType 'monthly' or 'biweekly' (legacy 'weekly' maps to monthly)
     * @return array [Carbon $start, Carbon $end]
     */
    public function getPreviousCompletedPeriod(string $periodType): array
    {
        $now = Carbon::now();

        return match ($this->normaliseLegacyWeekly($periodType)) {
            'biweekly' => $this->getPreviousCompletedBiweekly($now),
            default => $this->getPeriodBounds($now->copy()->subMonth(), 'monthly'),
        };
    }

    /**
     * Get the previous completed biweekly period.
     */
    private function getPreviousCompletedBiweekly(Carbon $now): array
    {
        $day = (int) $now->format('d');

        if ($day <= 14) {
            // We're in the first half, so the previous completed period is
            // the second half of last month (15th - end)
            $prevMonth = $now->copy()->subMonth();
            return [
                $prevMonth->copy()->startOfMonth()->addDays(14), // 15th
                $prevMonth->copy()->endOfMonth(),
            ];
        }

        // We're in the second half, so the previous completed period is
        // the first half of this month (1st - 14th)
        return [
            $now->copy()->startOfMonth(),
            $now->copy()->startOfMonth()->addDays(13), // 14th
        ];
    }

    /**
     * Check if the daily cron should trigger a tax calculation today.
     *
     * Shifted by 1 day from the period boundary to allow observer data
     * (which can lag 12-24h from ESI) to settle before calculating taxes.
     *
     * Monthly: calculate on the 2nd (for previous month)
     * Biweekly: calculate on the 2nd and 16th
     *
     * @param string $periodType 'monthly' or 'biweekly' (legacy 'weekly' maps to monthly)
     * @return bool
     */
    public function shouldCalculateToday(string $periodType): bool
    {
        $now = Carbon::now();

        return match ($this->normaliseLegacyWeekly($periodType)) {
            'biweekly' => $now->day === 2 || $now->day === 16,
            default => $now->day === 2,
        };
    }

    /**
     * Get all periods within a calendar month for a given period type.
     *
     * @param Carbon $month Any date in the target month
     * @param string $periodType 'monthly' or 'biweekly' (legacy 'weekly' maps to monthly)
     * @return array Array of [start, end] pairs
     */
    public function getPeriodsInMonth(Carbon $month, string $periodType): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return match ($this->normaliseLegacyWeekly($periodType)) {
            'biweekly' => [
                [$start->copy(), $start->copy()->addDays(13)],
                [$start->copy()->addDays(14), $end->copy()],
            ],
            default => [[$start->copy(), $end->copy()]],
        };
    }

    /**
     * Format a period for display.
     *
     * Monthly: "March 2026"
     * Biweekly: "Mar 1-14, 2026" / "Mar 15-31, 2026"
     * Weekly (legacy — historical rows only): "Mar 3-9, 2026"
     *
     * NOTE: 'weekly' was removed as a selectable period type in v1.0.3+,
     * but historical mining_taxes rows from earlier installs still carry
     * period_type = 'weekly'. This method retains the weekly branch so
     * those rows render correctly in Tax History / detail views. No new
     * weekly rows are written by the plugin going forward.
     *
     * @param Carbon $start
     * @param Carbon $end
     * @param string $periodType
     * @return string
     */
    public function formatPeriod(Carbon $start, Carbon $end, string $periodType): string
    {
        return match ($periodType) {
            'monthly' => $start->format('F Y'),
            'biweekly' => $start->format('M j') . '-' . $end->format('j, Y'),
            'weekly' => $start->format('M j') . '-' . (
                $start->month === $end->month
                    ? $end->format('j, Y')
                    : $end->format('M j, Y')
            ),
            default => $start->format('F Y'),
        };
    }

    /**
     * Check if a period has completed (end date is in the past).
     *
     * @param Carbon $end Period end date
     * @return bool
     */
    public function isPeriodComplete(Carbon $end): bool
    {
        return Carbon::now()->startOfDay()->gt($end);
    }

    /**
     * Calculate the due date for a period based on settings.
     *
     * @param Carbon $periodEnd
     * @return Carbon
     */
    public function calculateDueDate(Carbon $periodEnd): Carbon
    {
        $rates = $this->settingsService->getTaxRates();
        $deadlineDays = $rates['tax_payment_deadline_days'] ?? 7;

        return $periodEnd->copy()->addDays($deadlineDays);
    }
}
