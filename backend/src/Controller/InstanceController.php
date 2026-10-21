<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\SchedulerStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/instance')]
class InstanceController extends AbstractController
{
    public function __construct(private readonly SchedulerStatus $scheduler) {}

    // What this deployment can offer: depends on which optional services run
    // (e.g. the scheduler behind the `cron` profile), not on per-project config.
    #[Route('/capabilities', methods: ['GET'])]
    public function capabilities(): JsonResponse
    {
        return new JsonResponse([
            'autoTimeout'          => $this->scheduler->isAlive(),
            'schedulerLastBeatAt'  => $this->scheduler->lastBeat()?->format(\DateTimeInterface::RFC3339),
        ]);
    }
}
