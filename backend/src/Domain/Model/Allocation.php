<?php

declare(strict_types=1);

namespace App\Domain\Model;

final class Allocation
{
    public function __construct(
        public readonly int    $toMemberId,
        public readonly string $amount, // numeric string, always > 0
    ) {}
}
