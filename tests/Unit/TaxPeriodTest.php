<?php

namespace MiningManager\Tests\Unit;

use Carbon\Carbon;
use MiningManager\Services\Tax\TaxPeriodHelper;
use PHPUnit\Framework\TestCase;

class TaxPeriodTest extends TestCase
{
    private TaxPeriodHelper $periods;

    protected function setUp(): void
    {
        // The dates worked out here read no settings, and building the settings
        // service would take the framework with it.
        $this->periods = (new \ReflectionClass(TaxPeriodHelper::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private static function at(string $when): Carbon
    {
        return Carbon::parse($when, 'UTC');
    }

    /** @param array{0: Carbon, 1: Carbon} $period */
    private static function days(array $period): string
    {
        return $period[0]->toDateString() . ' to ' . $period[1]->toDateString();
    }

    public function test_a_month_runs_from_the_first_to_its_last_day(): void
    {
        $this->assertSame('2026-02-01 to 2026-02-28', self::days($this->periods->getPeriodBounds(self::at('2026-02-14'), 'monthly')));
        $this->assertSame('2028-02-01 to 2028-02-29', self::days($this->periods->getPeriodBounds(self::at('2028-02-29'), 'monthly')));
    }

    public function test_a_month_billed_twice_splits_after_the_14th(): void
    {
        $this->assertSame('2026-03-01 to 2026-03-14', self::days($this->periods->getPeriodBounds(self::at('2026-03-14'), 'biweekly')));
        $this->assertSame('2026-03-15 to 2026-03-31', self::days($this->periods->getPeriodBounds(self::at('2026-03-15'), 'biweekly')));
        $this->assertSame('2026-02-15 to 2026-02-28', self::days($this->periods->getPeriodBounds(self::at('2026-02-20'), 'biweekly')));
    }

    /** Weekly was removed, but old settings can still say it. A week crossing two months would be billed twice. */
    public function test_a_period_set_to_weekly_is_read_as_monthly(): void
    {
        $this->assertSame('2026-03-01 to 2026-03-31', self::days($this->periods->getPeriodBounds(self::at('2026-03-10'), 'weekly')));
        $this->assertCount(1, $this->periods->getPeriodsInMonth(self::at('2026-03-10'), 'weekly'));
    }

    public function test_the_periods_of_a_month_cover_every_day_once(): void
    {
        $this->assertSame(['2026-03-01 to 2026-03-31'], array_map([self::class, 'days'], $this->periods->getPeriodsInMonth(self::at('2026-03-20'), 'monthly')));
        $this->assertSame(
            ['2026-02-01 to 2026-02-14', '2026-02-15 to 2026-02-28'],
            array_map([self::class, 'days'], $this->periods->getPeriodsInMonth(self::at('2026-02-20'), 'biweekly'))
        );
    }

    /** Run on the 31st, going back one month has to land in the month before, not overflow back into this one. */
    public function test_the_last_finished_month_is_the_one_before_whatever_the_day(): void
    {
        foreach (['2026-10-31', '2026-03-29', '2026-03-31', '2026-01-01'] as $today) {
            Carbon::setTestNow(self::at($today . ' 02:15:00'));
            $expected = self::at($today)->startOfMonth()->subDay();

            $this->assertSame(
                $expected->copy()->startOfMonth()->toDateString() . ' to ' . $expected->toDateString(),
                self::days($this->periods->getPreviousCompletedPeriod('monthly')),
                $today
            );
        }
    }

    public function test_the_last_finished_half_month_steps_across_month_ends(): void
    {
        Carbon::setTestNow(self::at('2026-11-02 02:15:00'));
        $this->assertSame('2026-10-15 to 2026-10-31', self::days($this->periods->getPreviousCompletedPeriod('biweekly')));

        Carbon::setTestNow(self::at('2026-11-16 02:15:00'));
        $this->assertSame('2026-11-01 to 2026-11-14', self::days($this->periods->getPreviousCompletedPeriod('biweekly')));

        Carbon::setTestNow(self::at('2027-01-02 02:15:00'));
        $this->assertSame('2026-12-15 to 2026-12-31', self::days($this->periods->getPreviousCompletedPeriod('biweekly')));

        Carbon::setTestNow(self::at('2026-03-14 02:15:00'));
        $this->assertSame('2026-02-15 to 2026-02-28', self::days($this->periods->getPreviousCompletedPeriod('biweekly')));
    }

    public function test_bills_are_worked_out_the_day_after_a_period_boundary(): void
    {
        $days = ['2026-11-01' => [false, false], '2026-11-02' => [true, true], '2026-11-03' => [false, false], '2026-11-15' => [false, false], '2026-11-16' => [false, true]];

        foreach ($days as $today => [$monthly, $biweekly]) {
            Carbon::setTestNow(self::at($today . ' 02:15:00'));

            $this->assertSame($monthly, $this->periods->shouldCalculateToday('monthly'), "$today monthly");
            $this->assertSame($biweekly, $this->periods->shouldCalculateToday('biweekly'), "$today biweekly");
        }
    }

    public function test_a_period_is_complete_once_its_last_day_is_over(): void
    {
        $lastDay = self::at('2026-10-31')->endOfDay();

        Carbon::setTestNow(self::at('2026-10-31 23:59:00'));
        $this->assertFalse($this->periods->isPeriodComplete($lastDay));

        Carbon::setTestNow(self::at('2026-11-01 00:00:00'));
        $this->assertTrue($this->periods->isPeriodComplete($lastDay));
    }

    public function test_a_period_reads_the_way_the_pages_show_it(): void
    {
        $this->assertSame('October 2026', $this->periods->formatPeriod(self::at('2026-10-01'), self::at('2026-10-31'), 'monthly'));
        $this->assertSame('Oct 15-31, 2026', $this->periods->formatPeriod(self::at('2026-10-15'), self::at('2026-10-31'), 'biweekly'));
        // Bills from when weeks were a period still have to show.
        $this->assertSame('Sep 28-Oct 4, 2026', $this->periods->formatPeriod(self::at('2026-09-28'), self::at('2026-10-04'), 'weekly'));
    }
}
