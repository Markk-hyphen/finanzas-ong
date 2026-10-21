<?php

declare(strict_types=1);

namespace App\Domain\Service;

/**
 * Retry schedule for notification deliveries (docs/fase3-mail-telegram.md §2.3):
 * up to 5 attempts, waiting 1, 2, 4 and 8 minutes between them.
 */
final class DeliveryBackoff
{
    public const MAX_ATTEMPTS = 5;

    /** null = attempts exhausted, the delivery is failed for good. */
    public function nextAttemptAt(int $attemptsMade, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($attemptsMade >= self::MAX_ATTEMPTS) {
            return null;
        }

        $minutes = 2 ** max(0, $attemptsMade - 1);

        return $now->modify("+{$minutes} minutes");
    }
}
