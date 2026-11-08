<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Pricing\PriceProviderService;
use PHPUnit\Framework\TestCase;

class MarketPriceTest extends TestCase
{
    /**
     * Jita on 7 November 2026, as Manager Core held it from Fuzzwork. Each
     * side's figures describe its whole order book.
     */
    private const ZEOLITES = [
        'buy' => ['min' => 0.01, 'max' => 778.0, 'median' => 761.0, 'percentile' => 778.0],
        'sell' => ['min' => 1492.0, 'max' => 57000.0, 'median' => 2350.0, 'percentile' => 1494.75],
    ];

    private const TRITANIUM = [
        'buy' => ['min' => 0.01, 'max' => 4.05, 'median' => 3.42, 'percentile' => 3.97],
        'sell' => ['min' => 4.09, 'max' => 300100000.0, 'median' => 6.32, 'percentile' => 4.10],
    ];

    /** The lowest buy order is whatever someone bid at the bottom of the market. */
    public function test_buy_is_the_highest_buy_order(): void
    {
        $this->assertSame(778.0, PriceProviderService::managerCorePrice(self::ZEOLITES['buy'], 'buy'));
        $this->assertSame(4.05, PriceProviderService::managerCorePrice(self::TRITANIUM['buy'], 'buy'));
    }

    public function test_sell_is_the_lowest_sell_order(): void
    {
        $this->assertSame(1492.0, PriceProviderService::managerCorePrice(self::ZEOLITES['sell'], 'sell'));
        $this->assertSame(4.09, PriceProviderService::managerCorePrice(self::TRITANIUM['sell'], 'sell'));
    }

    public function test_the_split_is_halfway_between_the_highest_buy_and_the_lowest_sell(): void
    {
        $this->assertSame(1135.0, PriceProviderService::managerCorePrice(self::ZEOLITES, 'average'));
        $this->assertEqualsWithDelta(4.07, PriceProviderService::managerCorePrice(self::TRITANIUM, 'average'), 0.0001);
    }

    public function test_one_side_reads_the_same_from_an_answer_with_both(): void
    {
        $this->assertSame(778.0, PriceProviderService::managerCorePrice(self::ZEOLITES, 'buy'));
        $this->assertSame(1492.0, PriceProviderService::managerCorePrice(self::ZEOLITES, 'sell'));
    }

    /** Moon ore often trades on one side only, and half of that side is not its price. */
    public function test_an_ore_traded_on_one_side_is_priced_at_that_side(): void
    {
        $this->assertSame(778.0, PriceProviderService::managerCorePrice(['buy' => self::ZEOLITES['buy'], 'sell' => null], 'average'));
        $this->assertSame(1492.0, PriceProviderService::managerCorePrice(['buy' => null, 'sell' => self::ZEOLITES['sell']], 'average'));
        $this->assertSame(1492.0, PriceProviderService::managerCorePrice(['buy' => ['min' => 0.0, 'max' => 0.0], 'sell' => self::ZEOLITES['sell']], 'average'));
    }

    /** A plugin override's answer, like Janice's, is one price per side. */
    public function test_an_answer_of_one_price_per_side(): void
    {
        $janice = ['buy' => ['min' => 33.9, 'max' => 33.9], 'sell' => ['min' => 35.86, 'max' => 35.86]];

        $this->assertSame(33.9, PriceProviderService::managerCorePrice($janice, 'buy'));
        $this->assertEqualsWithDelta(34.88, PriceProviderService::managerCorePrice($janice, 'average'), 0.0001);
    }

    public function test_nothing_from_manager_core_is_no_price(): void
    {
        $this->assertSame(0.0, PriceProviderService::managerCorePrice(null, 'buy'));
        $this->assertSame(0.0, PriceProviderService::managerCorePrice(['buy' => null, 'sell' => null], 'average'));
        $this->assertSame(0.0, PriceProviderService::managerCorePrice(self::ZEOLITES['sell'], 'average'));
    }

    /** Fuzzwork sends its figures as text, and a side with no orders as zeros. */
    public function test_fuzzwork(): void
    {
        $zeolites = ['buy' => ['max' => '778', 'min' => '0.01'], 'sell' => ['min' => '1492', 'max' => '57000']];
        $buyOnly = ['buy' => ['max' => '20', 'min' => '0.01'], 'sell' => ['min' => '0', 'max' => '0']];
        $sellOnly = ['buy' => ['max' => '0', 'min' => '0'], 'sell' => ['min' => '15420', 'max' => '39990']];

        $this->assertSame(778.0, PriceProviderService::fuzzworkPrice($zeolites, 'buy'));
        $this->assertSame(1492.0, PriceProviderService::fuzzworkPrice($zeolites, 'sell'));
        $this->assertSame(1135.0, PriceProviderService::fuzzworkPrice($zeolites, 'average'));
        $this->assertSame(20.0, PriceProviderService::fuzzworkPrice($buyOnly, 'average'));
        $this->assertSame(15420.0, PriceProviderService::fuzzworkPrice($sellOnly, 'average'));
        $this->assertSame(0.0, PriceProviderService::fuzzworkPrice(null, 'sell'));
    }
}
