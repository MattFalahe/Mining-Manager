<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Moon\MoonChunkModel;
use PHPUnit\Framework\TestCase;

class MoonChunkModelTest extends TestCase
{
    public function test_the_rate_runs_between_the_two_observed_points(): void
    {
        $this->assertSame(21000, MoonChunkModel::ratePerHour(0.70));
        $this->assertSame(26000, MoonChunkModel::ratePerHour(0.85));
        $this->assertSame(31000, MoonChunkModel::ratePerHour(1.00));
    }

    public function test_below_70_percent_the_rate_falls_to_its_floor(): void
    {
        $this->assertSame(18000, MoonChunkModel::ratePerHour(0.60));
        $this->assertSame(15000, MoonChunkModel::ratePerHour(0.50));
        $this->assertSame(15000, MoonChunkModel::ratePerHour(0.10));
    }

    public function test_the_volume_is_the_rate_over_the_days(): void
    {
        $this->assertSame(31000 * 24 * 28, MoonChunkModel::volume(1.00, 28));
        $this->assertSame(21000 * 24 * 6, MoonChunkModel::volume(0.70, 6));
    }
}
