<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Diagnostic\TaxTraceTargets;
use PHPUnit\Framework\TestCase;

/**
 * The periods Tax Trace offers for an account: its bills' periods as they were
 * billed, then its tax profile's periods for the days no bill covers.
 */
class TaxTraceTargetsTest extends TestCase
{
    private static function bill(int $id, string $start, string $end, string $type): array
    {
        return ['id' => $id, 'period_start' => $start, 'period_end' => $end, 'period_type' => $type];
    }

    /** @return array<int, array{start: string, end: string, type: string}> */
    private static function halves(string $month): array
    {
        $last = date('t', strtotime($month . '-01'));

        return [
            ['start' => "{$month}-01", 'end' => "{$month}-14", 'type' => 'biweekly'],
            ['start' => "{$month}-15", 'end' => "{$month}-{$last}", 'type' => 'biweekly'],
        ];
    }

    private static function spans(array $choices): array
    {
        return array_map(fn (array $choice) => $choice['start'] . ' to ' . $choice['end'], $choices);
    }

    public function test_a_month_billed_twice_offers_each_half_on_its_own(): void
    {
        $choices = TaxTraceTargets::periodChoices(
            [self::bill(331, '2026-11-01', '2026-11-14', 'biweekly')],
            self::halves('2026-11'),
            '2026-11-25'
        );

        $this->assertSame(['2026-11-15 to 2026-11-30', '2026-11-01 to 2026-11-14'], self::spans($choices));
        $this->assertSame([], $choices[0]['bills']);
        $this->assertTrue($choices[0]['open']);
        $this->assertSame([331], $choices[1]['bills']);
        $this->assertFalse($choices[1]['open']);
    }

    public function test_a_month_billed_whole_stays_whole_when_the_profile_bills_twice_now(): void
    {
        $choices = TaxTraceTargets::periodChoices(
            [self::bill(12, '2026-09-01', '2026-09-30', 'monthly')],
            array_merge(self::halves('2026-09'), self::halves('2026-10')),
            '2026-11-25'
        );

        $this->assertSame(['2026-10-15 to 2026-10-31', '2026-10-01 to 2026-10-14', '2026-09-01 to 2026-09-30'], self::spans($choices));
        $this->assertSame('monthly', $choices[2]['type']);
    }

    public function test_a_period_not_started_is_not_offered(): void
    {
        $choices = TaxTraceTargets::periodChoices([], self::halves('2026-11'), '2026-11-10');

        $this->assertSame(['2026-11-01 to 2026-11-14'], self::spans($choices));
        $this->assertTrue($choices[0]['open']);
    }

    public function test_bills_for_the_same_period_share_one_choice(): void
    {
        // A deleted bill and the one made again over the same days.
        $choices = TaxTraceTargets::periodChoices(
            [self::bill(40, '2026-10-01', '2026-10-14', 'biweekly'), self::bill(77, '2026-10-01', '2026-10-14', 'biweekly')],
            [],
            '2026-11-25'
        );

        $this->assertCount(1, $choices);
        $this->assertSame([40, 77], $choices[0]['bills']);
    }

    public function test_an_old_week_across_two_months_is_offered_as_billed(): void
    {
        $choices = TaxTraceTargets::periodChoices(
            [self::bill(3, '2026-04-27', '2026-05-03', 'weekly')],
            [['start' => '2026-05-01', 'end' => '2026-05-31', 'type' => 'monthly'], ['start' => '2026-06-01', 'end' => '2026-06-30', 'type' => 'monthly']],
            '2026-11-25'
        );

        // May overlaps the billed week, so only the week is offered for those
        // days; the rest of May is there to trace as a calendar month.
        $this->assertSame(['2026-06-01 to 2026-06-30', '2026-04-27 to 2026-05-03'], self::spans($choices));
        $this->assertSame('weekly', $choices[1]['type']);
    }
}
