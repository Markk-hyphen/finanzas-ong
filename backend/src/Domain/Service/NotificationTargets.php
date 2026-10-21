<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\ActivityEvent;

/**
 * Who should be notified in-app for each event (MANIFIESTO amendment E2, see
 * docs/fase2-eventos-notificaciones.md). Deliberately narrow: only the events
 * where silence could cost someone money or a chance to object. Every other
 * ActivityEvent still reaches the audit log — it just has no entry here.
 */
final class NotificationTargets
{
    /** @return int[] member ids to notify, deduplicated */
    public function for(string $eventType, ?int $fromMemberId, ?int $toMemberId): array
    {
        $ids = match ($eventType) {
            // Born confirmed: the receiver needs to know it exists and can still be rejected.
            ActivityEvent::PAYMENT_CREATED_OPTIMISTIC => [$toMemberId],
            // Silence until the deadline confirms it: the receiver must hear about it while they can still object.
            ActivityEvent::PAYMENT_CREATED_AUTO_TIMEOUT => [$toMemberId],
            // The objection window closed in silence: both sides should know it's final now.
            ActivityEvent::PAYMENT_AUTO_CONFIRMED => [$fromMemberId, $toMemberId],
            // The payer took back a pending share: the receiver expected that money.
            ActivityEvent::PAYMENT_WITHDRAWN => [$toMemberId],
            // The receiver rejected their share: the payer needs to redo the payment.
            ActivityEvent::PAYMENT_REJECTED => [$fromMemberId],
            default => [],
        };

        return array_values(array_unique(array_filter($ids, fn(?int $id) => $id !== null)));
    }
}
