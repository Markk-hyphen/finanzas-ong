<?php

declare(strict_types=1);

namespace App\Domain\Model;

final class MovementRecord
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $type,
        public readonly string  $status,
        public readonly ?int    $fromMemberId,
        public readonly ?int    $toMemberId,
        public readonly ?string $amount,
        public readonly ?string $batchId,
        public readonly ?int    $cancelsMovementId,
    ) {}
}
