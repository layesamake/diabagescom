<?php

namespace Tests\Unit\Domain\Cash;

use App\Domain\Cash\CashCount;
use PHPUnit\Framework\TestCase;

class CashCountTest extends TestCase
{
    public function test_the_total_adds_every_denomination(): void
    {
        $count = ['10000' => 1, '5000' => 0, '2000' => 2, '1000' => 0, '500' => 1, '100' => 3];

        $this->assertSame(14800, CashCount::total($count));
    }

    public function test_the_total_accepts_integer_keys_and_missing_values(): void
    {
        $this->assertSame(5500, CashCount::total([5000 => 1, 500 => 1]));
        $this->assertSame(0, CashCount::total([]));
    }

    public function test_normalize_fills_every_denomination_with_non_negative_integers(): void
    {
        $count = CashCount::normalize([5000 => '2', '500' => 1]);

        $this->assertSame(['10000' => 0, '5000' => 2, '2000' => 0, '1000' => 0, '500' => 1, '100' => 0], $count);
        $this->assertSame(['10000', '5000', '2000', '1000', '500', '100'], array_map('strval', array_keys($count)));
    }

    public function test_normalize_never_lets_a_negative_count_reduce_the_total(): void
    {
        $this->assertSame(0, CashCount::total(CashCount::normalize([10000 => -3])));
    }

    public function test_the_validation_rules_only_allow_the_known_denominations(): void
    {
        $rules = CashCount::rules('count');

        $this->assertSame(['array:10000,5000,2000,1000,500,100'], $rules['count']);
        $this->assertArrayHasKey('count.10000', $rules);
        $this->assertArrayHasKey('count.100', $rules);
        $this->assertCount(7, $rules);
    }
}
