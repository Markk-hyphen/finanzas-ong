<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Strategy\ProportionalToCredit;
use PHPUnit\Framework\TestCase;

class ProportionalToCreditTest extends TestCase
{
    private ProportionalToCredit $strategy;

    protected function setUp(): void
    {
        $this->strategy = new ProportionalToCredit();
    }

    public function testSumEqualsAmountExact(): void
    {
        $balances = [1 => '-1000000.00', 2 => '500000.00', 3 => '500000.00'];
        $allocations = $this->strategy->allocate(1, '1000000.00', $balances);

        $this->assertAllocationsSum('1000000.00', $allocations);
    }

    public function testIndivisibleAmountNoCentLost(): void
    {
        // 1.00 / 3 cannot be divided exactly — largest remainder must handle it
        $balances = [1 => '-1.00', 2 => '0.34', 3 => '0.33', 4 => '0.33'];
        $allocations = $this->strategy->allocate(1, '1.00', $balances);

        $this->assertAllocationsSum('1.00', $allocations);
    }

    public function testClassicThirdsSplit(): void
    {
        // 1,000,000 / 3 — the canonical "lost cent" trap
        $balances = [1 => '-1000000.00', 2 => '333334.00', 3 => '333333.00', 4 => '333333.00'];
        $allocations = $this->strategy->allocate(1, '1000000.00', $balances);

        $this->assertAllocationsSum('1000000.00', $allocations);
        $this->assertCount(3, $allocations);
    }

    public function testProportionalDistribution(): void
    {
        // Creditor A has 600, creditor B has 400 → payer pays 100
        // A should get 60, B should get 40
        $balances = [1 => '-100.00', 2 => '600.00', 3 => '400.00'];
        $allocations = $this->strategy->allocate(1, '100.00', $balances);

        $this->assertAllocationsSum('100.00', $allocations);
        $map = $this->toMap($allocations);
        $this->assertSame('60.00', $map[2]);
        $this->assertSame('40.00', $map[3]);
    }

    public function testPayerExcludedFromCreditors(): void
    {
        $balances = [1 => '-100.00', 2 => '100.00'];
        $allocations = $this->strategy->allocate(1, '50.00', $balances);

        $map = $this->toMap($allocations);
        $this->assertArrayNotHasKey(1, $map);
        $this->assertSame('50.00', $map[2]);
    }

    public function testNoCreditorReturnsEmpty(): void
    {
        $balances = [1 => '-100.00', 2 => '-50.00'];
        $allocations = $this->strategy->allocate(1, '50.00', $balances);

        $this->assertEmpty($allocations);
    }

    public function testSingleCreditorGetsFull(): void
    {
        $balances = [1 => '-500.00', 2 => '1000.00'];
        $allocations = $this->strategy->allocate(1, '500.00', $balances);

        $map = $this->toMap($allocations);
        $this->assertSame('500.00', $map[2]);
        $this->assertAllocationsSum('500.00', $allocations);
    }

    // Property: sum always equals requested amount for random inputs
    public function testSumPropertyWithRandomInputs(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $creditorsCount = random_int(1, 5);
            $balances = [99 => '-' . (string)(random_int(1, 100000) / 100)];
            $totalCredit = '0.00';
            for ($c = 1; $c <= $creditorsCount; $c++) {
                $credit = number_format(random_int(100, 10000) / 100, 2, '.', '');
                $balances[$c] = $credit;
                $totalCredit  = bcadd($totalCredit, $credit, 2);
            }
            $payerDebt = bcmul($balances[99], '-1', 2);
            $amount    = number_format((float) bcdiv($payerDebt, '2', 2), 2, '.', '');
            if (bccomp($amount, '0.01', 2) < 0) {
                continue;
            }

            $allocations = $this->strategy->allocate(99, $amount, $balances);
            $this->assertAllocationsSum($amount, $allocations, "Sum mismatch on iteration {$i}");
        }
    }

    // --- helpers ---

    private function assertAllocationsSum(string $expected, array $allocations, string $msg = ''): void
    {
        $sum = array_reduce($allocations, fn($c, $a) => bcadd($c, $a->amount, 2), '0.00');
        $this->assertSame($expected, $sum, $msg ?: "Allocation sum {$sum} ≠ expected {$expected}");
    }

    private function toMap(array $allocations): array
    {
        $map = [];
        foreach ($allocations as $a) {
            $map[$a->toMemberId] = $a->amount;
        }
        return $map;
    }
}
