<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Domain\Model\Allocation;

interface AllocationStrategy
{
    /**
     * @param array<int, string> $balances  memberId => balance (positive = creditor)
     * @return Allocation[]
     */
    public function allocate(int $payerId, string $amount, array $balances): array;
}
