<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\InstanceHeartbeat;
use Doctrine\ORM\EntityManagerInterface;

// Whether this instance runs the scheduler (profile `cron`). Detected from the
// heartbeat the scheduler writes on every run instead of a config flag, so a
// scheduler that died shows up as "not alive" too.
class SchedulerStatus
{
    // The auto-confirm job runs every 5 minutes; three missed runs = not alive.
    private const MAX_AGE_SECONDS = 900;

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function lastBeat(): ?\DateTimeImmutable
    {
        return $this->em->find(InstanceHeartbeat::class, InstanceHeartbeat::SCHEDULER)?->getBeatAt();
    }

    public function isAlive(?\DateTimeImmutable $now = null): bool
    {
        $last = $this->lastBeat();
        $now ??= new \DateTimeImmutable();

        return $last !== null && $now->getTimestamp() - $last->getTimestamp() <= self::MAX_AGE_SECONDS;
    }

    public function beat(?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $heartbeat = $this->em->find(InstanceHeartbeat::class, InstanceHeartbeat::SCHEDULER);

        if ($heartbeat === null) {
            $this->em->persist(new InstanceHeartbeat(InstanceHeartbeat::SCHEDULER, $now));
        } else {
            $heartbeat->beat($now);
        }

        $this->em->flush();
    }
}
