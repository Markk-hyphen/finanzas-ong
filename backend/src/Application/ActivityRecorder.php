<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Service\NotificationTargets;
use App\Entity\AuditLog;
use App\Entity\Member;
use App\Entity\Movement;
use App\Entity\Notification;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

// Single place every ledger-affecting write goes through to leave a trail
// (MANIFIESTO amendment E2). Always writes the full audit_log row; writes a
// targeted Notification + Mercure push only for the events NotificationTargets
// cares about. Call from inside the same transaction as the ledger write —
// if the transaction rolls back, the trail rolls back with it.
class ActivityRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationTargets $targets,
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * $fromMemberId/$toMemberId drive who gets notified (NotificationTargets) and
     * default to $movement's own from/to — pass them explicitly when the movement
     * that should appear in the log (e.g. a cancellation row) isn't the one whose
     * sides matter for targeting (e.g. the payment it cancels).
     */
    public function record(
        Project $project,
        string $eventType,
        ?Movement $movement,
        ?Member $actor,
        ?array $payload = null,
        ?int $fromMemberId = null,
        ?int $toMemberId = null,
    ): void {
        $this->em->persist(new AuditLog($project, $eventType, $movement, $actor, $payload));

        $fromMemberId ??= $movement?->getFromMember()?->getId();
        $toMemberId   ??= $movement?->getToMember()?->getId();
        $recipientIds = $this->targets->for($eventType, $fromMemberId !== null ? (int) $fromMemberId : null, $toMemberId !== null ? (int) $toMemberId : null);

        foreach ($recipientIds as $recipientId) {
            $recipient = $this->em->find(Member::class, $recipientId);
            if ($recipient === null) {
                continue;
            }

            $notification = new Notification($recipient, $eventType, $movement, $payload);
            $this->em->persist($notification);
            // Flush now: need the generated id before publishing, and this
            // runs inside the caller's own transaction either way.
            $this->em->flush();

            $this->publisher->publishCreated($recipientId, (int) $notification->getId(), $eventType);
        }
    }
}
