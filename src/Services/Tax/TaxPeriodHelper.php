<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
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
     * @return string 'monthly', 'biweekly', or 'weekly'
     */
    public function getConfiguredPeriodType(): string
    {
        $rates = $this->settingsService->getTaxRates();
        return $rates['tax_calculation_period'] ?? 'monthly';
    }

    /**
     * Get the start and end dates for the period containing the given date.
     *
     * @param Carbon $date Any date within the desired period
     * @param string $periodType 'monthly', 'biweekly', or 'weekly'
     * @return array [Carbon $start, Carbon $end]
     */
    public function getPeriodBounds(Carbon $date, string $periodType): array
    {
        return match ($periodType) {
            'weekly' => $this->getWeekBounds($date),
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
     * Get weekly period bounds (ISO week: Monday-Sunday).
     */
    private function getWeekBounds(Carbon $date): array
    {
        return [
            $date->copy()->startOfWeek(Carbon::MONDAY),
            $date->copy()->endOfWeek(Carbon::SUNDAY),
        ];
    }

    /**
     * Get the most recently completed period.
     *
     * @param string $periodType
     * @return array [Carbon $start, Carbon $end]
     */
    public function getPreviousCompletedPeriod(string $periodType): array
    {
        $now = Carbon::now();

        return match ($periodType) {
            'weekly' => $this->getPeriodBounds($now->copy()->subWeek(), 'weekly'),
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
     * Shifted by 1 day to allow observer data (which can lag 12-24h from ESI)
     * to settle before calculating taxes.
     *
     * Monthly: calculate on the 2nd (for previous month)
     * Biweekly: calculate on the 2nd and 16th
     * Weekly: calculate on Tuesdays
     *
     * @param string $periodType
     * @return bool
     */
    public function shouldCalculateToday(string $periodType): bool
    {
        $now = Carbon::now();

        return match ($periodType) {
            'weekly' => $now->isTuesday(),
            'biweekly' => $now->day === 2 || $now->day === 16,
            default => $now->day === 2,
        };
    }

    /**
     * Get all periods within a calendar month for a given period type.
     *
     * @param Carbon $month Any date in the target month
     * @param string $periodType
     * @return array Array of [start, end] pairs
     */
    public function getPeriodsInMonth(Carbon $month, string $periodType): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return match ($periodType) {
            'weekly' => $this->getWeeksInRange($start, $end),
            'biweekly' => [
                [$start->copy(), $start->copy()->addDays(13)],
                [$start->copy()->addDays(14), $end->copy()],
            ],
            default => [[$start->copy(), $end->copy()]],
        };
    }

    /**
     * Get all ISO weeks that overlap with a date range.
     */
    private function getWeeksInRange(Carbon $start, Carbon $end): array
    {
        $weeks = [];
        $current = $start->copy()->startOfWeek(Carbon::MONDAY);

        while ($current->lte($end)) {
            $weekEnd = $current->copy()->endOfWeek(Carbon::SUNDAY);
            $weeks[] = [$current->copy(), $weekEnd->copy()];
            $current->addWeek();
        }

        return $weeks;
    }

    /**
     * Format a period for display.
     *
     * Monthly: "March 2026"
     * Biweekly: "Mar 1-14, 2026" / "Mar 15-31, 2026"
     * Weekly: "Mar 3-9, 2026"
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
