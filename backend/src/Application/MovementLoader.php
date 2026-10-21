<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Model\MovementRecord;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

class MovementLoader
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @return MovementRecord[] */
    public function forProject(Project $project): array
    {
        $rows = $this->em->createQuery(
            'SELECT m FROM App\Entity\Movement m WHERE m.project = :project ORDER BY m.createdAt ASC'
        )
        ->setParameter('project', $project)
        ->getResult();

        return array_map(fn($m) => new MovementRecord(
            id: (int) $m->getId(),
            type: $m->getType(),
            status: $m->getStatus(),
            fromMemberId: $m->getFromMember() !== null ? (int) $m->getFromMember()->getId() : null,
            toMemberId: $m->getToMember() !== null ? (int) $m->getToMember()->getId() : null,
            amount: $m->getAmount(),
            batchId: $m->getBatchId(),
            cancelsMovementId: $m->getCancelsMovement() !== null ? (int) $m->getCancelsMovement()->getId() : null,
            createdAt: $m->getCreatedAt(),
            createdById: (int) $m->getCreatedBy()->getId(),
            confirmedVia: $m->getConfirmedVia(),
        ), $rows);
    }
}
