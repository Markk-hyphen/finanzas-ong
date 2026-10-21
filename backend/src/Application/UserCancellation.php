<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Model\ActivityEvent;
use App\Domain\Service\CancellationPolicy;
use App\Entity\Member;
use App\Entity\Movement;
use App\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

// Non-admin cancellations (undo / withdraw / reject). Must run inside a
// transaction: takes the same per-project lock as every other ledger write.
class UserCancellation
{
    private const EVENT_FOR_KIND = [
        // self_correction covers both expenses and payments; resolved per-target below.
        CancellationPolicy::KIND_WITHDRAWAL => ActivityEvent::PAYMENT_WITHDRAWN,
        CancellationPolicy::KIND_REJECTION  => ActivityEvent::PAYMENT_REJECTED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MovementLoader $movementLoader,
        private readonly CancellationPolicy $policy,
        private readonly ActivityRecorder $activityRecorder,
    ) {}

    /**
     * Cancels every target the policy allows for $action and skips the rest.
     *
     * @param Movement[] $targets movements of $project
     * @return int number of cancellations written
     */
    public function cancel(Project $project, array $targets, Member $actor, string $action, string $reason): int
    {
        $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

        $records   = [];
        $cancelled = [];
        foreach ($this->movementLoader->forProject($project) as $r) {
            $records[$r->id] = $r;
            if ($r->type === 'cancellation' && $r->cancelsMovementId !== null) {
                $cancelled[$r->cancelsMovementId] = true;
            }
        }

        $settings = $project->getSettings();
        $now      = new \DateTimeImmutable();
        $count    = 0;

        foreach ($targets as $target) {
            $id = (int) $target->getId();
            if (isset($cancelled[$id]) || !isset($records[$id])) {
                continue;
            }

            $kind = $this->policy->kindFor($action, $records[$id], (int) $actor->getId(), $settings, $now);
            if ($kind === null) {
                continue;
            }

            $cancellation = Movement::cancellationOf($target, $actor, $kind, $reason);
            $this->em->persist($cancellation);

            $eventType = $kind === CancellationPolicy::KIND_SELF_CORRECTION
                ? ($target->getType() === Movement::TYPE_EXPENSE ? ActivityEvent::EXPENSE_UNDONE : ActivityEvent::PAYMENT_SELF_CORRECTED)
                : self::EVENT_FOR_KIND[$kind];

            $fromId = $target->getFromMember()?->getId();
            $toId   = $target->getToMember()?->getId();
            $this->activityRecorder->record(
                $project,
                $eventType,
                $cancellation,
                $actor,
                fromMemberId: $fromId !== null ? (int) $fromId : null,
                toMemberId: $toId !== null ? (int) $toId : null,
            );
            $count++;
        }

        $this->em->flush();

        return $count;
    }
}
