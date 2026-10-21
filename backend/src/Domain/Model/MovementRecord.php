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
        // Only needed by CancellationPolicy; balance calculation ignores them.
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?int    $createdById = null,
        public readonly ?string $confirmedVia = null, // receiver | optimistic | timeout
    ) {}
}
