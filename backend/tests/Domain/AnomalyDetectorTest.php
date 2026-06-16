<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Model\MovementRecord;
use App\Domain\Service\AnomalyDetector;
use PHPUnit\Framework\TestCase;

class AnomalyDetectorTest extends TestCase
{
    private AnomalyDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new AnomalyDetector();
    }

    public function testNormalAmountIsNotAnomalous(): void
    {
        $movements = [$this->expense(1, '100.00'), $this->expense(2, '110.00'), $this->expense(3, '90.00')];

        $this->assertFalse($this->detector->isAnomalous('120.00', $movements));
    }

    public function testAmountOverFiveTimesMedianIsAnomalous(): void
    {
        $movements = [$this->expense(1, '100.00'), $this->expense(2, '100.00'), $this->expense(3, '100.00')];

        // median = 100, threshold = 500, 501 should trigger
        $this->assertTrue($this->detector->isAnomalous('501.00', $movements));
    }

    public function testExactlyFiveTimesMedianIsNotAnomalous(): void
    {
        $movements = [$this->expense(1, '100.00')];

        // 5 × 100 = 500 → not anomalous (must be strictly greater)
        $this->assertFalse($this->detector->isAnomalous('500.00', $movements));
    }

    public function testNoExistingExpensesIsNotAnomalous(): void
    {
        $this->assertFalse($this->detector->isAnomalous('999999.00', []));
    }

    public function testWarningDoesNotBlockLargeExpense(): void
    {
        // The detector only reports — the caller decides whether to block
        $movements = [$this->expense(1, '100.00')];
        $result    = $this->detector->isAnomalous('9999.00', $movements);

        $this->assertTrue($result); // returns true (warning) but does not throw
    }

    public function testMedianUsedNotMean(): void
    {
        // Mean would be skewed by the 10000 outlier; median should be 100
        $movements = [
            $this->expense(1, '100.00'),
            $this->expense(2, '100.00'),
            $this->expense(3, '10000.00'),
        ];
        $median = '100.00'; // not (100+100+10000)/3 = 3400

        // 501 > 5×100 → anomalous by median, but NOT by mean-based threshold
        $this->assertTrue($this->detector->isAnomalous('501.00', $movements));
    }

    private function expense(int $id, string $amount): MovementRecord
    {
        return new MovementRecord($id, 'expense', 'confirmed', 1, null, $amount, null, null);
    }
}
