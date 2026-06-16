<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\MovementLoader;
use App\Application\ShareLoader;
use App\Domain\Model\Allocation;
use App\Domain\Service\AllocationValidator;
use App\Domain\Service\BalanceCalculator;
use App\Domain\Strategy\ProportionalToCredit;
use App\Entity\Member;
use App\Entity\Movement;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class PaymentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MovementLoader $movementLoader,
        private readonly ShareLoader $shareLoader,
        private readonly BalanceCalculator $calculator,
        private readonly ProportionalToCredit $strategy,
        private readonly AllocationValidator $validator,
    ) {}

    #[Route('/projects/{id}/payments/preview', methods: ['POST'])]
    public function preview(int $id, Request $request): JsonResponse
    {
        $result = $this->resolveAllocation($id, $request);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new JsonResponse([
            'allocations' => $this->serializeAllocations($result['allocations'], $result['memberNames']),
        ]);
    }

    #[Route('/projects/{id}/payments', methods: ['POST'])]
    public function create(int $id, Request $request): JsonResponse
    {
        $result = $this->resolveAllocation($id, $request);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        /** @var Allocation[] $allocations */
        $allocations = $result['allocations'];
        /** @var Project $project */
        $project = $result['project'];
        /** @var Member $member */
        $member  = $result['member'];

        $batchId = $this->generateUuid();

        foreach ($allocations as $allocation) {
            $toMember = $this->em->find(Member::class, $allocation->toMemberId);
            if ($toMember === null) {
                continue;
            }
            $payment = new Movement($project, Movement::TYPE_PAYMENT, $member);
            $payment->setFromMember($member);
            $payment->setToMember($toMember);
            $payment->setAmount($allocation->amount);
            $payment->setStatus(Movement::STATUS_PENDING);
            $payment->setBatchId($batchId);
            $this->em->persist($payment);
        }

        $this->em->flush();

        return new JsonResponse([
            'batchId'     => $batchId,
            'allocations' => $this->serializeAllocations($allocations, $result['memberNames']),
        ], 201);
    }

    #[Route('/payments/{batchId}/confirm', methods: ['POST'])]
    public function confirm(string $batchId): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        $payments = $this->em->createQuery(
            'SELECT m FROM App\Entity\Movement m
             WHERE m.batchId = :batchId
             AND m.toMember = :member
             AND m.status = :pending'
        )
        ->setParameter('batchId', $batchId)
        ->setParameter('member', $member)
        ->setParameter('pending', Movement::STATUS_PENDING)
        ->getResult();

        if (empty($payments)) {
            return new JsonResponse(['error' => 'No pending payments found for this batch.'], 404);
        }

        foreach ($payments as $payment) {
            $payment->setStatus(Movement::STATUS_CONFIRMED);
        }

        $this->em->flush();

        return new JsonResponse(['confirmed' => count($payments)]);
    }

    /** @return JsonResponse|array{allocations: Allocation[], memberNames: array<int,string>, project: Project, member: Member} */
    private function resolveAllocation(int $projectId, Request $request): JsonResponse|array
    {
        /** @var Member $member */
        $member = $this->getUser();

        $project = $this->em->find(Project::class, $projectId);
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

        $body   = json_decode($request->getContent(), true) ?? [];
        $amount = trim((string) ($body['amount'] ?? ''));

        if ($amount === '' || !is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            return new JsonResponse(['error' => 'amount must be a positive number.'], 400);
        }

        $movements = $this->movementLoader->forProject($project);
        $shares    = $this->shareLoader->forProject($project);
        $balances  = $this->calculator->calculate($movements, $shares);
        $memberId  = (int) $member->getId();

        try {
            $allocations = $this->strategy->allocate($memberId, $amount, $balances);
            $this->validator->validate($allocations, $balances, $memberId, $amount);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        if (empty($allocations)) {
            return new JsonResponse(['error' => 'No creditors to pay.'], 422);
        }

        $toMemberIds = array_map(fn(Allocation $a) => $a->toMemberId, $allocations);
        $creditors   = $this->em->createQuery(
            'SELECT m FROM App\Entity\Member m WHERE m.id IN (:ids)'
        )
        ->setParameter('ids', $toMemberIds)
        ->getResult();

        $memberNames = [];
        foreach ($creditors as $m) {
            $memberNames[(int) $m->getId()] = $m->getName();
        }

        return [
            'allocations' => $allocations,
            'memberNames' => $memberNames,
            'project'     => $project,
            'member'      => $member,
        ];
    }

    /** @param Allocation[] $allocations */
    private function serializeAllocations(array $allocations, array $memberNames): array
    {
        return array_map(fn(Allocation $a) => [
            'toMemberId'   => $a->toMemberId,
            'toMemberName' => $memberNames[$a->toMemberId] ?? null,
            'amount'       => $a->amount,
        ], $allocations);
    }

    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
