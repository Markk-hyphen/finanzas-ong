<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Model\MemberShare;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

class ShareLoader
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @return MemberShare[] */
    public function forProject(Project $project): array
    {
        $rows = $this->em->createQuery(
            'SELECT mp FROM App\Entity\MemberProject mp WHERE mp.project = :project'
        )
        ->setParameter('project', $project)
        ->getResult();

        return array_map(
            fn($mp) => new MemberShare(
                memberId: (int) $mp->getMember()->getId(),
                percentage: $mp->getPercentage(),
            ),
            $rows,
        );
    }
}
