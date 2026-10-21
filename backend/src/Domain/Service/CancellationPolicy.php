<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\MovementRecord;
use App\Domain\Model\ProjectSettings;

/**
 * Who may cancel what without being admin (MANIFIESTO amendment E1). Every
 * allowed action still produces a cancellation row; this only decides whether
 * the actor may create it and which kind it is. Admin cancellation is not
 * covered here: admin may cancel any active movement.
 *
 * Assumes the movement is active (not already cancelled); callers filter that.
 */
class CancellationPolicy
{
    public const ACTION_UNDO     = 'undo';      // author undoes their own expense
    public const ACTION_WITHDRAW = 'withdraw';  // payer takes back a payment
    public const ACTION_REJECT   = 'reject';    // receiver rejects their share

    public const KIND_ADMIN           = 'admin';
    public const KIND_WITHDRAWAL      = 'withdrawal';
    public const KIND_SELF_CORRECTION = 'self_correction';
    public const KIND_REJECTION       = 'rejection';

    public const KINDS = [self::KIND_ADMIN, self::KIND_WITHDRAWAL, self::KIND_SELF_CORRECTION, self::KIND_REJECTION];

    private const ACTIONS = [self::ACTION_UNDO, self::ACTION_WITHDRAW, self::ACTION_REJECT];

    /** Cancellation kind the actor may create for $action on $m, or null if not allowed. */
    public function kindFor(
        string $action,
        MovementRecord $m,
        int $actorId,
        ProjectSettings $settings,
        \DateTimeImmutable $now,
    ): ?string {
        $selfWindow      = $settings->selfCancelMinutes * 60;
        $objectionWindow = $settings->objectionWindowHours * 3600;

        if ($m->type === 'expense') {
            return $action === self::ACTION_UNDO
                && $m->fromMemberId === $actorId
                && $this->within($m, $selfWindow, $now)
                ? self::KIND_SELF_CORRECTION
                : null;
        }

        if ($m->type !== 'payment') {
            return null;
        }

        if ($action === self::ACTION_WITHDRAW && $m->fromMemberId === $actorId) {
            if ($m->status === 'pending') {
                return self::KIND_WITHDRAWAL;
            }
            // Born confirmed (optimistic): the payer can still fix a typo for a short while.
            if ($m->confirmedVia === 'optimistic' && $this->within($m, $selfWindow, $now)) {
                return self::KIND_SELF_CORRECTION;
            }
        }

        if ($action === self::ACTION_REJECT && $m->toMemberId === $actorId) {
            if ($m->status === 'pending') {
                return self::KIND_REJECTION;
            }
            // Confirmed by the receiver or by timeout means the receiver already answered.
            if ($m->confirmedVia === 'optimistic' && $this->within($m, $objectionWindow, $now)) {
                return self::KIND_REJECTION;
            }
        }

        return null;
    }

    /** @return string[] actions the actor can take on $m right now */
    public function actionsFor(MovementRecord $m, int $actorId, ProjectSettings $settings, \DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            self::ACTIONS,
            fn(string $action) => $this->kindFor($action, $m, $actorId, $settings, $now) !== null,
        ));
    }

    private function within(MovementRecord $m, int $seconds, \DateTimeImmutable $now): bool
    {
        return $seconds > 0
            && $m->createdAt !== null
            && $now < $m->createdAt->modify("+{$seconds} seconds");
    }
}
