<?php

declare(strict_types=1);

namespace App\Application;

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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MovementLoader $movementLoader,
        private readonly CancellationPolicy $policy,
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

            $this->em->persist(Movement::cancellationOf($target, $actor, $kind, $reason));
            $count++;
        }

        $this->em->flush();

        return $count;
    }
}
