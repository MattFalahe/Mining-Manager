<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Ledger\LateMining;
use PHPUnit\Framework\TestCase;

class LateMiningTest extends TestCase
{
    private static function billedRow(?string $notes = null): object
    {
        return (object) [
            'total_value' => 1000.0,
            'ore_value' => 900.0,
            'mineral_value' => 1000.0,
            'unit_price' => 10.0,
            'tax_rate' => 20.0,
            'tax_amount' => 200.0,
            'notes' => $notes,
        ];
    }

    private const EXTRA = ['total_value' => 500.0, 'ore_value' => 450.0, 'mineral_value' => 500.0];

    public function test_only_the_extra_is_added_at_todays_value(): void
    {
        $row = LateMining::growth(self::billedRow(), 150, self::EXTRA);

        $this->assertSame(150, $row['quantity']);
        $this->assertSame(1500.0, $row['total_value']);
        $this->assertSame(1350.0, $row['ore_value']);
        $this->assertSame(1500.0, $row['mineral_value']);
        $this->assertSame(10.0, $row['unit_price']);
    }

    /** What the member was billed is a record. A growing row must not reach it. */
    public function test_a_billed_row_keeps_the_tax_it_was_billed(): void
    {
        $row = LateMining::growth(self::billedRow(), 150, self::EXTRA);

        $this->assertSame(['quantity', 'ore_value', 'mineral_value', 'total_value', 'unit_price', 'notes'], array_keys($row));
    }

    public function test_the_note_goes_on_once_and_never_replaces_one(): void
    {
        $first = LateMining::growth(self::billedRow(), 150, self::EXTRA);
        $this->assertSame(LateMining::GROWTH_NOTE, $first['notes']);

        $again = LateMining::growth(self::billedRow($first['notes']), 160, self::EXTRA);
        $this->assertSame(LateMining::GROWTH_NOTE, $again['notes']);

        $added = LateMining::growth(self::billedRow('Checked by hand.'), 150, self::EXTRA);
        $this->assertSame('Checked by hand. ' . LateMining::GROWTH_NOTE, $added['notes']);

        $late = LateMining::growth(self::billedRow(LateMining::NEW_ROW_NOTE), 150, self::EXTRA);
        $this->assertSame(LateMining::NEW_ROW_NOTE, $late['notes']);
    }

    public function test_a_row_with_no_quantity_keeps_its_price(): void
    {
        $this->assertSame(10.0, LateMining::growth(self::billedRow(), 0, [])['unit_price']);
    }

    private static function taxed(float $tax, float $discount = 0.0): array
    {
        return ['class' => 'moon_r16', 'taxed' => true, 'rate' => 10.0, 'tax' => $tax, 'discount' => $discount, 'reason' => null];
    }

    private static function untaxed(string $reason): array
    {
        return ['class' => 'moon_r16', 'taxed' => false, 'rate' => 0.0, 'tax' => 0.0, 'discount' => 0.0, 'reason' => $reason];
    }

    private static function row(int $id, ?string $notes = null): object
    {
        return (object) ['id' => $id, 'notes' => $notes];
    }

    /** The refinery report that came in after a day with no tax on it was billed. */
    public function test_a_row_that_arrived_after_the_bill_is_not_taxed(): void
    {
        $figures = LateMining::billedDay(
            [self::row(1, LateMining::NEW_ROW_NOTE), self::row(2)],
            [1 => self::taxed(616785.0), 2 => self::untaxed('moon_counted')],
            0.0
        );

        $this->assertFalse($figures[1]['taxed']);
        $this->assertSame(0.0, $figures[1]['tax']);
        $this->assertSame(0.0, $figures[1]['rate']);
        $this->assertSame('after_bill', $figures[1]['reason']);
        $this->assertSame('moon_r16', $figures[1]['class']);
        $this->assertSame('moon_counted', $figures[2]['reason']);

        $notCounted = LateMining::billedDay([self::row(1, LateMining::NEW_ROW_NOTE)], [1 => self::untaxed('not_counted')], 0.0);
        $this->assertSame('after_bill', $notCounted[1]['reason']);
    }

    public function test_a_late_row_that_would_not_have_been_taxed_keeps_that_reason(): void
    {
        $figures = LateMining::billedDay(
            [self::row(1, LateMining::NEW_ROW_NOTE)],
            [1 => self::untaxed('other_refinery')],
            0.0
        );

        $this->assertSame('other_refinery', $figures[1]['reason']);
    }

    /** The refinery report that grew after the day was billed on its first figure. */
    public function test_a_row_that_grew_keeps_only_what_was_billed(): void
    {
        $figures = LateMining::billedDay(
            [self::row(1, LateMining::GROWTH_NOTE), self::row(2)],
            [1 => self::taxed(457779.0, 100.0), 2 => self::untaxed('moon_counted')],
            301861.0
        );

        $this->assertTrue($figures[1]['taxed']);
        $this->assertSame(10.0, $figures[1]['rate']);
        $this->assertSame(301861.0, $figures[1]['tax']);
        $this->assertSame(round(100.0 * 301861.0 / 457779.0, 2), $figures[1]['discount']);
    }

    public function test_the_other_rows_of_the_day_keep_their_share(): void
    {
        $rows = [self::row(1, 'Checked by hand. ' . LateMining::GROWTH_NOTE), self::row(2, LateMining::GROWTH_NOTE), self::row(3)];
        $figures = [1 => self::taxed(300.0), 2 => self::taxed(100.0), 3 => self::taxed(150.0)];

        $split = LateMining::billedDay($rows, $figures, 350.0);
        $this->assertSame(150.0, $split[1]['tax']);
        $this->assertSame(50.0, $split[2]['tax']);
        $this->assertSame(150.0, $split[3]['tax']);

        // Never more than the rows come to now, and never below nothing.
        $this->assertSame(300.0, LateMining::billedDay($rows, $figures, 900.0)[1]['tax']);
        $this->assertSame(0.0, LateMining::billedDay($rows, $figures, 100.0)[1]['tax']);
    }

    /** The page shows the billed part as taxed and the rest as not taxed, so it needs the split. */
    public function test_a_row_cut_back_to_its_bill_says_how_much_the_bill_covered(): void
    {
        $rows = [self::row(1, LateMining::GROWTH_NOTE), self::row(2, LateMining::GROWTH_NOTE), self::row(3)];
        $figures = [1 => self::taxed(300.0), 2 => self::taxed(100.0), 3 => self::taxed(150.0)];

        $split = LateMining::billedDay($rows, $figures, 350.0);
        $this->assertSame(0.5, $split[1]['billed_share']);
        $this->assertSame(0.5, $split[2]['billed_share']);
        $this->assertArrayNotHasKey('billed_share', $split[3]);

        $this->assertSame(0.0, LateMining::billedDay($rows, $figures, 100.0)[1]['billed_share']);

        // Nothing to explain when the bill covered all of it.
        $this->assertArrayNotHasKey('billed_share', LateMining::billedDay($rows, $figures, 900.0)[1]);

        $grown = LateMining::billedDay(
            [self::row(1, LateMining::GROWTH_NOTE), self::row(2)],
            [1 => self::taxed(457779.0), 2 => self::untaxed('moon_counted')],
            301861.0
        );
        $this->assertEqualsWithDelta(301861.0 / 457779.0, $grown[1]['billed_share'], 1e-9);
        $this->assertArrayNotHasKey('billed_share', $grown[2]);
    }

    public function test_a_day_with_nothing_late_comes_back_as_it_was(): void
    {
        $figures = [1 => self::taxed(300.0), 2 => self::untaxed('settings')];

        $this->assertSame($figures, LateMining::billedDay([self::row(1, 'Checked by hand.'), self::row(2)], $figures, 120.0));
    }
}
