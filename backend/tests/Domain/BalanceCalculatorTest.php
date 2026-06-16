<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Model\MemberShare;
use App\Domain\Model\MovementRecord;
use App\Domain\Service\BalanceCalculator;
use PHPUnit\Framework\TestCase;

class BalanceCalculatorTest extends TestCase
{
    private BalanceCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new BalanceCalculator();
    }

    // Conservation property: Σ balances == 0 for any valid set of movements
    public function testConservationWithOnlyExpenses(): void
    {
        $movements = [
            $this->expense(1, 1, '600.00'),
            $this->expense(2, 2, '400.00'),
        ];
        $shares = [
            new MemberShare(1, '50.00'),
            new MemberShare(2, '50.00'),
        ];

        $balances = $this->calc->calculate($movements, $shares);

        $this->assertSumIsZero($balances);
    }

    public function testConservationWithExpensesAndConfirmedPayments(): void
    {
        // Member 1 paid 1000, member 2 paid nothing. Equal shares → member 2 owes 500 to member 1.
        // Member 2 pays 500 to member 1 (confirmed).
        $movements = [
            $this->expense(1, 1, '1000.00'),
            $this->payment(2, 2, 1, '500.00', 'confirmed'),
        ];
        $shares = [
            new MemberShare(1, '50.00'),
            new MemberShare(2, '50.00'),
        ];

        $balances = $this->calc->calculate($movements, $shares);

        $this->assertSumIsZero($balances);
        $this->assertSame('0.00', $balances[1]);
        $this->assertSame('0.00', $balances[2]);
    }

    public function testPendingPaymentDoesNotAffectBalance(): void
    {
        $movements = [
            $this->expense(1, 1, '1000.00'),
            $this->payment(2, 2, 1, '500.00', 'pending'),
        ];
        $shares = [
            new MemberShare(1, '50.00'),
            new MemberShare(2, '50.00'),
        ];

        $balances = $this->calc->calculate($movements, $shares);

        // Pending payment must be invisible — balances unchanged vs no payment
        $this->assertSame('500.00', $balances[1]);
        $this->assertSame('-500.00', $balances[2]);
        $this->assertSumIsZero($balances);
    }

    public function testExpenseOnlyAffectsFromMember(): void
    {
        $movements = [$this->expense(1, 1, '300.00')];
        $shares    = [new MemberShare(1, '100.00')];

        $balances = $this->calc->calculate($movements, $shares);

        // Member paid 300 and owes 100% of 300 → net 0
        $this->assertSame('0.00', $balances[1]);
    }

    public function testCancelledExpenseDoesNotAffectBalance(): void
    {
        $movements = [
            $this->expense(1, 1, '1000.00'),
            $this->cancellation(2, 1),       // cancels movement id=1
        ];
        $shares = [
            new MemberShare(1, '50.00'),
            new MemberShare(2, '50.00'),
        ];

        // As if the expense never happened
        $balances = $this->calc->calculate($movements, $shares);

        $this->assertSame('0.00', $balances[1]);
        $this->assertSame('0.00', $balances[2]);
        $this->assertSumIsZero($balances);
    }

    public function testCancelledPaymentDoesNotAffectBalance(): void
    {
        $expense   = $this->expense(1, 1, '1000.00');
        $payment   = $this->payment(2, 2, 1, '500.00', 'confirmed');
        $cancel    = $this->cancellation(3, 2); // cancels payment

        $shares = [
            new MemberShare(1, '50.00'),
            new MemberShare(2, '50.00'),
        ];

        $balancesWithCancelledPayment = $this->calc->calculate([$expense, $payment, $cancel], $shares);
        $balancesWithNoPayment        = $this->calc->calculate([$expense], $shares);

        $this->assertSame($balancesWithNoPayment[1], $balancesWithCancelledPayment[1]);
        $this->assertSame($balancesWithNoPayment[2], $balancesWithCancelledPayment[2]);
        $this->assertSumIsZero($balancesWithCancelledPayment);
    }

    public function testUnequalSharesConserve(): void
    {
        $movements = [$this->expense(1, 1, '1000.00')];
        $shares    = [
            new MemberShare(1, '70.00'),
            new MemberShare(2, '30.00'),
        ];

        $balances = $this->calc->calculate($movements, $shares);

        $this->assertSumIsZero($balances);
        $this->assertSame('300.00', $balances[1]);  // paid 1000, owes 700 → +300
        $this->assertSame('-300.00', $balances[2]); // paid 0, owes 300 → -300
    }

    public function testPaymentsDoNotChangeTotalCost(): void
    {
        $movements = [
            $this->expense(1, 1, '600.00'),
            $this->payment(2, 2, 1, '200.00', 'confirmed'),
        ];
        $shares = [
            new MemberShare(1, '50.00'),
            new MemberShare(2, '50.00'),
        ];

        $balances = $this->calc->calculate($movements, $shares);

        // Total cost is still 600 (only expenses count)
        // Member 1: +600 (expense) + 0 (no payment from) - 200 (received) - 300 (share) = +100
        // Member 2: 0 + 200 (paid) - 0 - 300 (share) = -100
        $this->assertSame('100.00', $balances[1]);
        $this->assertSame('-100.00', $balances[2]);
        $this->assertSumIsZero($balances);
    }

    // Property-based style: random sets of expenses must always conserve
    public function testConservationPropertyWithRandomExpenses(): void
    {
        $shares = [
            new MemberShare(1, '33.34'),
            new MemberShare(2, '33.33'),
            new MemberShare(3, '33.33'),
        ];

        for ($i = 0; $i < 50; $i++) {
            $movements = [];
            $id = 1;
            foreach ([1, 2, 3] as $memberId) {
                $count = random_int(0, 5);
                for ($j = 0; $j < $count; $j++) {
                    $amount    = (string) (random_int(1, 100000) / 100);
                    $movements[] = $this->expense($id++, $memberId, number_format((float)$amount, 2, '.', ''));
                }
            }
            $balances = $this->calc->calculate($movements, $shares);
            $this->assertSumIsZero($balances, "Conservation failed on iteration {$i}");
        }
    }

    // --- helpers ---

    private function expense(int $id, int $fromMemberId, string $amount): MovementRecord
    {
        return new MovementRecord($id, 'expense', 'confirmed', $fromMemberId, null, $amount, null, null);
    }

    private function payment(int $id, int $fromMemberId, int $toMemberId, string $amount, string $status): MovementRecord
    {
        return new MovementRecord($id, 'payment', $status, $fromMemberId, $toMemberId, $amount, null, null);
    }

    private function cancellation(int $id, int $cancelsMovementId): MovementRecord
    {
        return new MovementRecord($id, 'cancellation', 'confirmed', null, null, null, null, $cancelsMovementId);
    }

    /** @param array<int, string> $balances */
    private function assertSumIsZero(array $balances, string $message = ''): void
    {
        $sum = array_reduce($balances, fn($carry, $b) => bcadd($carry, $b, 2), '0.00');
        $this->assertSame('0.00', $sum, $message ?: 'Conservation invariant violated: Σ balances ≠ 0');
    }
}
