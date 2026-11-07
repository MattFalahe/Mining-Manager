<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Ledger\RefineryCoverage;
use PHPUnit\Framework\TestCase;

class RefineryCoverageTest extends TestCase
{
    private const OURS = 144534752;
    private const OTHER = 98000003;

    private static function report(int $corporationId, int $quantity): array
    {
        return ['corporation_id' => $corporationId, 'quantity' => $quantity];
    }

    public function test_nothing_is_left_when_the_refinery_reported_all_of_it(): void
    {
        $this->assertSame(0, RefineryCoverage::remainder(5875, [self::report(self::OURS, 5875)], self::OURS, true));
    }

    /** The row can hold 3,001 from a partial first report. Only the real 1,000 is left. */
    public function test_what_is_left_is_the_difference_whatever_the_row_holds(): void
    {
        $this->assertSame(1000, RefineryCoverage::remainder(6875, [self::report(self::OURS, 5875)], self::OURS, true));
    }

    public function test_reports_from_two_refineries_in_the_system_add_up(): void
    {
        $reports = [self::report(self::OURS, 2000), self::report(self::OURS, 4000)];

        $this->assertSame(0, RefineryCoverage::remainder(6000, $reports, self::OURS, true));
        $this->assertSame(500, RefineryCoverage::remainder(6500, $reports, self::OURS, true));
    }

    /** SeAT's personal figure can trail the refinery's. That is covered, never a negative row. */
    public function test_a_report_bigger_than_the_personal_figure_leaves_nothing(): void
    {
        $this->assertSame(0, RefineryCoverage::remainder(4000, [self::report(self::OURS, 5875)], self::OURS, true));
    }

    public function test_no_report_in_the_system_is_nothing_to_compare(): void
    {
        $this->assertNull(RefineryCoverage::remainder(2000, [], self::OURS, true));
    }

    public function test_no_figure_from_seat_is_nothing_to_compare(): void
    {
        $this->assertNull(RefineryCoverage::remainder(null, [self::report(self::OURS, 5875)], self::OURS, true));
    }

    /** From the day profiles tax mining, another corporation's refinery counts like ours. */
    public function test_another_corporations_refinery_counts_on_a_day_taxed_by_profiles(): void
    {
        $this->assertSame(0, RefineryCoverage::remainder(4000, [self::report(self::OTHER, 4000)], self::OURS, true));
        $this->assertSame(1000, RefineryCoverage::remainder(6000, [self::report(self::OURS, 3000), self::report(self::OTHER, 2000)], self::OURS, true));
    }

    /** Before that the summaries leave it out, so the personal row is the only place that mining shows. */
    public function test_another_corporations_refinery_does_not_count_before_profiles(): void
    {
        $this->assertNull(RefineryCoverage::remainder(4000, [self::report(self::OTHER, 4000)], self::OURS, false));
        $this->assertSame(3000, RefineryCoverage::remainder(6000, [self::report(self::OURS, 3000), self::report(self::OTHER, 2000)], self::OURS, false));
    }

    public function test_our_refinery_counts_before_profiles(): void
    {
        $this->assertSame(0, RefineryCoverage::remainder(5875, [self::report(self::OURS, 5875)], self::OURS, false));
    }

    public function test_with_no_moon_owner_nothing_counts_before_profiles(): void
    {
        $this->assertNull(RefineryCoverage::remainder(5875, [self::report(self::OURS, 5875)], null, false));
    }
}
