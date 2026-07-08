<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Entity\Movement;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

class ProjectGuard
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** Suma de los porcentajes de las membresías del proyecto, con escala 2. */
    public function sumPercentage(Project $project): string
    {
        $sum = $this->em->createQuery(
            'SELECT COALESCE(SUM(mp.percentage), 0) FROM App\Entity\MemberProject mp WHERE mp.project = :project'
        )->setParameter('project', $project)->getSingleScalarResult();

        return bcadd((string) $sum, '0', 2);
    }

    /** Un proyecto es "activo" (usable) si sus % suman exactamente 100.00 (INV-1). */
    public function isActive(Project $project): bool
    {
        return bccomp($this->sumPercentage($project), '100.00', 2) === 0;
    }

    /** True si el proyecto ya tiene algún movimiento (INV-2: congela la membresía). */
    public function hasMovements(Project $project): bool
    {
        $count = $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\Movement m WHERE m.project = :project'
        )->setParameter('project', $project)->getSingleScalarResult();

        return (int) $count > 0;
    }
}
