<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\MovementLoader;
use App\Application\ShareLoader;
use App\Domain\Service\BalanceCalculator;
use App\Entity\Member;
use App\Entity\MemberProject;
use App\Entity\Movement;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ProjectController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MovementLoader $movementLoader,
        private readonly ShareLoader $shareLoader,
        private readonly BalanceCalculator $calculator,
    ) {}

    #[Route('/projects', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        $memberships = $this->em->createQuery(
            'SELECT mp, p FROM App\Entity\MemberProject mp JOIN mp.project p WHERE mp.member = :member ORDER BY p.name ASC'
        )
        ->setParameter('member', $member)
        ->getResult();

        return new JsonResponse(array_map(fn(MemberProject $mp) => [
            'id'         => $mp->getProject()->getId(),
            'name'       => $mp->getProject()->getName(),
            'percentage' => $mp->getPercentage(),
            'createdAt'  => $mp->getProject()->getCreatedAt()->format(\DateTimeInterface::RFC3339),
        ], $memberships));
    }

    #[Route('/projects/{id}/dashboard', methods: ['GET'])]
    public function dashboard(int $id): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        $project = $this->em->find(Project::class, $id);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        $membership = $this->em->createQuery(
            'SELECT mp FROM App\Entity\MemberProject mp WHERE mp.member = :member AND mp.project = :project'
        )
        ->setParameter('member', $member)
        ->setParameter('project', $project)
        ->getOneOrNullResult();

        if (!$membership) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        $movements = $this->movementLoader->forProject($project);
        $shares    = $this->shareLoader->forProject($project);
        $balances  = $this->calculator->calculate($movements, $shares);
        $totalCost = $this->calculator->computeTotalCost($movements);

        // Member names for all project members
        $allMemberships = $this->em->createQuery(
            'SELECT mp, m FROM App\Entity\MemberProject mp JOIN mp.member m WHERE mp.project = :project'
        )
        ->setParameter('project', $project)
        ->getResult();

        $memberNames = [];
        foreach ($allMemberships as $mp) {
            $memberNames[(int) $mp->getMember()->getId()] = $mp->getMember()->getName();
        }

        $balancesData = [];
        foreach ($balances as $memberId => $balance) {
            $balancesData[] = [
                'memberId' => $memberId,
                'name'     => $memberNames[$memberId] ?? null,
                'balance'  => $balance,
            ];
        }

        // Cancelled movement IDs (for filtering the list)
        $cancelledIds = [];
        foreach ($movements as $m) {
            if ($m->type === 'cancellation' && $m->cancelsMovementId !== null) {
                $cancelledIds[$m->cancelsMovementId] = true;
            }
        }

        // Member's own movements (expenses/payments, active only)
        $myMovements = $this->em->createQuery(
            'SELECT m FROM App\Entity\Movement m
             WHERE m.project = :project
             AND m.type != :cancel
             AND (m.fromMember = :member OR m.toMember = :member)
             ORDER BY m.createdAt DESC'
        )
        ->setParameter('project', $project)
        ->setParameter('cancel', Movement::TYPE_CANCELLATION)
        ->setParameter('member', $member)
        ->getResult();

        $movementsData = [];
        foreach ($myMovements as $m) {
            if (isset($cancelledIds[(int) $m->getId()])) {
                continue;
            }
            $movementsData[] = [
                'id'           => $m->getId(),
                'type'         => $m->getType(),
                'status'       => $m->getStatus(),
                'amount'       => $m->getAmount(),
                'fromMemberId' => $m->getFromMember() !== null ? (int) $m->getFromMember()->getId() : null,
                'toMemberId'   => $m->getToMember() !== null ? (int) $m->getToMember()->getId() : null,
                'batchId'      => $m->getBatchId(),
                'material'     => $m->getMaterial()?->getName(),
                'quantity'     => $m->getQuantity(),
                'createdAt'    => $m->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            ];
        }

        // Pending payments where member is receiver
        $pending = $this->em->createQuery(
            'SELECT m FROM App\Entity\Movement m
             WHERE m.project = :project
             AND m.type = :payment
             AND m.status = :pending
             AND m.toMember = :member
             ORDER BY m.createdAt DESC'
        )
        ->setParameter('project', $project)
        ->setParameter('payment', Movement::TYPE_PAYMENT)
        ->setParameter('pending', Movement::STATUS_PENDING)
        ->setParameter('member', $member)
        ->getResult();

        $pendingData = array_map(fn(Movement $m) => [
            'id'             => $m->getId(),
            'batchId'        => $m->getBatchId(),
            'fromMemberId'   => $m->getFromMember() !== null ? (int) $m->getFromMember()->getId() : null,
            'fromMemberName' => $m->getFromMember()?->getName(),
            'amount'         => $m->getAmount(),
            'createdAt'      => $m->getCreatedAt()->format(\DateTimeInterface::RFC3339),
        ], $pending);

        return new JsonResponse([
            'project'              => ['id' => $project->getId(), 'name' => $project->getName()],
            'totalCost'            => $totalCost,
            'balances'             => $balancesData,
            'movements'            => $movementsData,
            'pendingConfirmations' => $pendingData,
        ]);
    }
}
