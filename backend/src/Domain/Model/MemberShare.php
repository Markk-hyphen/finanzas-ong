<?php

declare(strict_types=1);

namespace App\Domain\Model;

final class MemberShare
{
    public function __construct(
        public readonly int    $memberId,
        public readonly string $percentage, // numeric string, e.g. "33.33"
    ) {}
}
