<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\MovementLoader;
use App\Application\ShareLoader;
use App\Domain\Service\BalanceCalculator;
use App\Domain\Service\DecimalParser;
use App\Domain\Service\ProjectGuard;
use App\Entity\Member;
use App\Entity\MemberProject;
use App\Entity\Movement;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class ProjectController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MovementLoader $movementLoader,
        private readonly ShareLoader $shareLoader,
        private readonly BalanceCalculator $calculator,
        private readonly ProjectGuard $projectGuard,
        private readonly DecimalParser $decimals,
    ) {}

    #[Route('/projects', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            return new JsonResponse(['error' => 'name must not be empty.'], 400);
        }

        $project = new Project($name);
        $this->em->persist($project);
        $this->em->flush();

        return new JsonResponse(['id' => $project->getId(), 'name' => $project->getName(), 'active' => false], 201);
    }

    #[Route('/projects/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rename(int $id, Request $request): JsonResponse
    {
        $project = $this->em->find(Project::class, $id);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            return new JsonResponse(['error' => 'name must not be empty.'], 400);
        }

        $project->setName($name);
        $this->em->flush();

        return new JsonResponse(['id' => $project->getId(), 'name' => $project->getName()]);
    }

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

    #[Route('/projects/{id}/members', methods: ['GET'])]
    public function members(int $id): JsonResponse
    {
        $project = $this->em->find(Project::class, $id);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        /** @var Member $member */
        $member = $this->getUser();
        if (!$this->projectGuard->canView($member, $project)) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        $memberships = $this->em->createQuery(
            'SELECT mp, m FROM App\Entity\MemberProject mp JOIN mp.member m WHERE mp.project = :project ORDER BY m.name ASC'
        )
        ->setParameter('project', $project)
        ->getResult();

        return new JsonResponse(array_map(fn(MemberProject $mp) => [
            'id'         => $mp->getMember()->getId(),
            'name'       => $mp->getMember()->getName(),
            'username'   => $mp->getMember()->getUsername(),
            'email'      => $mp->getMember()->getEmail(),
            'percentage' => $mp->getPercentage(),
        ], $memberships));
    }

    #[Route('/projects/{id}/members', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addMember(int $id, Request $request): JsonResponse
    {
        $project = $this->em->find(Project::class, $id);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        $body     = json_decode($request->getContent(), true) ?? [];
        $memberId = (int) ($body['memberId'] ?? 0);
        $member   = $memberId > 0 ? $this->em->find(Member::class, $memberId) : null;
        if (!$member) {
            return new JsonResponse(['error' => 'Member not found.'], 404);
        }

        if ($this->projectGuard->hasMovements($project)) {
            return new JsonResponse(
                ['error' => 'No se puede modificar la membresía de un proyecto que ya tiene movimientos.'],
                409
            );
        }

        $existing = $this->em->createQuery(
            'SELECT mp FROM App\Entity\MemberProject mp WHERE mp.member = :member AND mp.project = :project'
        )
        ->setParameter('member', $member)
        ->setParameter('project', $project)
        ->getOneOrNullResult();

        if ($existing !== null) {
            return new JsonResponse(['error' => 'This member is already part of the project.'], 409);
        }

        $percentage = $this->decimals->parse($body['percentage'] ?? null, 3, 2);
        if ($percentage === null) {
            return new JsonResponse(['error' => 'percentage must be a number with at most 2 decimals.'], 400);
        }

        if (bccomp($percentage, '0', 2) <= 0 || bccomp($percentage, '100.00', 2) > 0) {
            return new JsonResponse(['error' => 'percentage must be greater than 0 and at most 100.'], 422);
        }

        $newSum = bcadd($this->projectGuard->sumPercentage($project), $percentage, 2);
        if (bccomp($newSum, '100.00', 2) > 0) {
            return new JsonResponse(['error' => 'La suma de porcentajes del proyecto no puede superar 100%.'], 422);
        }

        $membership = new MemberProject($member, $project, $percentage);
        $this->em->persist($membership);
        $this->em->flush();

        return new JsonResponse([
            'projectId'     => $project->getId(),
            'memberId'      => $member->getId(),
            'percentage'    => $percentage,
            'projectActive' => $this->projectGuard->isActive($project),
        ], 201);
    }

    #[Route('/projects/{id}/members/{memberId}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function removeMember(int $id, int $memberId): JsonResponse
    {
        $project = $this->em->find(Project::class, $id);
        $member  = $this->em->find(Member::class, $memberId);
        if (!$project || !$member) {
            return new JsonResponse(['error' => 'Project or member not found.'], 404);
        }

        $membership = $this->em->createQuery(
            'SELECT mp FROM App\Entity\MemberProject mp WHERE mp.member = :member AND mp.project = :project'
        )
        ->setParameter('member', $member)
        ->setParameter('project', $project)
        ->getOneOrNullResult();

        if (!$membership) {
            return new JsonResponse(['error' => 'Membership not found.'], 404);
        }

        if ($this->projectGuard->hasMovements($project)) {
            return new JsonResponse(
                ['error' => 'No se puede modificar la membresía de un proyecto que ya tiene movimientos.'],
                409
            );
        }

        $this->em->remove($membership);
        $this->em->flush();

        return new JsonResponse([
            'projectId'     => $project->getId(),
            'memberId'      => $member->getId(),
            'projectActive' => $this->projectGuard->isActive($project),
        ]);
    }

    #[Route('/projects/{id}/overview', methods: ['GET'])]
    public function overview(int $id): JsonResponse
    {
        $project = $this->em->find(Project::class, $id);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        /** @var Member $member */
        $member = $this->getUser();
        if (!$this->projectGuard->canView($member, $project)) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        $movements = $this->movementLoader->forProject($project);
        $shares    = $this->shareLoader->forProject($project);
        $balances  = $this->calculator->calculate($movements, $shares);
        $totalCost = $this->calculator->computeTotalCost($movements);

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

        $cancelledIds = [];
        foreach ($movements as $m) {
            if ($m->type === 'cancellation' && $m->cancelsMovementId !== null) {
                $cancelledIds[$m->cancelsMovementId] = true;
            }
        }

        $allMovements = $this->em->createQuery(
            'SELECT m FROM App\Entity\Movement m
             WHERE m.project = :project
             AND m.type != :cancel
             ORDER BY m.createdAt DESC'
        )
        ->setParameter('project', $project)
        ->setParameter('cancel', Movement::TYPE_CANCELLATION)
        ->getResult();

        $movementsData = [];
        foreach ($allMovements as $m) {
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

        $pending = $this->em->createQuery(
            'SELECT m FROM App\Entity\Movement m
             WHERE m.project = :project
             AND m.type = :payment
             AND m.status = :pending
             AND NOT EXISTS (SELECT c.id FROM App\Entity\Movement c WHERE c.cancelsMovement = m)
             ORDER BY m.createdAt DESC'
        )
        ->setParameter('project', $project)
        ->setParameter('payment', Movement::TYPE_PAYMENT)
        ->setParameter('pending', Movement::STATUS_PENDING)
        ->getResult();

        $pendingData = array_map(fn(Movement $m) => [
            'id'             => $m->getId(),
            'batchId'        => $m->getBatchId(),
            'fromMemberId'   => $m->getFromMember() !== null ? (int) $m->getFromMember()->getId() : null,
            'fromMemberName' => $m->getFromMember()?->getName(),
            'toMemberId'     => $m->getToMember() !== null ? (int) $m->getToMember()->getId() : null,
            'toMemberName'   => $m->getToMember()?->getName(),
            'amount'         => $m->getAmount(),
            'createdAt'      => $m->getCreatedAt()->format(\DateTimeInterface::RFC3339),
        ], $pending);

        return new JsonResponse([
            'project'        => ['id' => $project->getId(), 'name' => $project->getName()],
            'totalCost'      => $totalCost,
            'balances'       => $balancesData,
            'movements'      => $movementsData,
            'pendingPayments' => $pendingData,
        ]);
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
             AND NOT EXISTS (SELECT c.id FROM App\Entity\Movement c WHERE c.cancelsMovement = m)
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

        // Own balance with unconfirmed payments reserved: what is still payable.
        $projected = $this->calculator->calculate($movements, $shares, includePending: true);

        return new JsonResponse([
            'project'              => ['id' => $project->getId(), 'name' => $project->getName()],
            'totalCost'            => $totalCost,
            'balances'             => $balancesData,
            'myProjectedBalance'   => $projected[(int) $member->getId()] ?? '0.00',
            'movements'            => $movementsData,
            'pendingConfirmations' => $pendingData,
        ]);
    }
}
