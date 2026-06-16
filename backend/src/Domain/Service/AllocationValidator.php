<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\Allocation;

class AllocationValidator
{
    private const SCALE = 2;

    /**
     * @param Allocation[]       $allocations
     * @param array<int, string> $balances
     * @throws \DomainException
     */
    public function validate(array $allocations, array $balances, int $payerId, string $amount): void
    {
        $payerBalance = $balances[$payerId] ?? '0.00';

        if (bccomp($payerBalance, '0', self::SCALE) >= 0) {
            throw new \DomainException('Payer has no debt to pay.');
        }

        $maxPayable = bcmul($payerBalance, '-1', self::SCALE);
        if (bccomp($amount, $maxPayable, self::SCALE) > 0) {
            throw new \DomainException(
                "Payment amount {$amount} exceeds payer's debt {$maxPayable}.",
            );
        }

        $sum = '0.00';
        foreach ($allocations as $allocation) {
            $credit = $balances[$allocation->toMemberId] ?? '0.00';
            if (bccomp($credit, '0', self::SCALE) <= 0) {
                throw new \DomainException(
                    "Member {$allocation->toMemberId} is not a creditor.",
                );
            }
            if (bccomp($allocation->amount, $credit, self::SCALE) > 0) {
                throw new \DomainException(
                    "Allocation {$allocation->amount} exceeds member {$allocation->toMemberId}'s credit {$credit}.",
                );
            }
            $sum = bcadd($sum, $allocation->amount, self::SCALE);
        }

        if (bccomp($sum, $amount, self::SCALE) !== 0) {
            throw new \DomainException(
                "Allocation sum {$sum} does not equal payment amount {$amount}.",
            );
        }
    }
}
