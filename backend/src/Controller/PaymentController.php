<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\MovementLoader;
use App\Application\ShareLoader;
use App\Application\UserCancellation;
use App\Domain\Service\CancellationPolicy;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use App\Domain\Model\Allocation;
use App\Domain\Model\ProjectSettings;
use App\Domain\Service\AllocationValidator;
use App\Domain\Service\BalanceCalculator;
use App\Domain\Service\DecimalParser;
use App\Domain\Service\ProjectGuard;
use App\Domain\Strategy\ProportionalToCredit;
use App\Entity\Member;
use App\Entity\Movement;
use App\Entity\Project;
use Doctrine\DBAL\LockMode;
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
        private readonly ProjectGuard $projectGuard,
        private readonly DecimalParser $decimals,
        private readonly UserCancellation $userCancellation,
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
        // Validate and persist under a lock on the project row: two concurrent
        // payments would otherwise both validate against the same balances.
        return $this->em->wrapInTransaction(fn() => $this->persistPayment($id, $request));
    }

    private function persistPayment(int $id, Request $request): JsonResponse
    {
        $result = $this->resolveAllocation($id, $request, lock: true);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        /** @var Allocation[] $allocations */
        $allocations = $result['allocations'];
        /** @var Project $project */
        $project = $result['project'];
        /** @var Member $member */
        $member  = $result['member'];

        $batchId  = $this->generateUuid();
        $settings = $project->getSettings();
        $now      = new \DateTimeImmutable();
        // The project's mode is applied now and frozen in each row (E1).
        $autoConfirmAt = $settings->confirmationMode === ProjectSettings::MODE_AUTO_TIMEOUT
            ? $now->modify("+{$settings->objectionWindowHours} hours")
            : null;

        foreach ($allocations as $allocation) {
            $toMember = $this->em->find(Member::class, $allocation->toMemberId);
            if ($toMember === null) {
                continue;
            }
            $payment = new Movement($project, Movement::TYPE_PAYMENT, $member);
            $payment->setFromMember($member);
            $payment->setToMember($toMember);
            $payment->setAmount($allocation->amount);
            $payment->setBatchId($batchId);

            if ($settings->confirmationMode === ProjectSettings::MODE_OPTIMISTIC) {
                $payment->confirm(null, 'optimistic', $now);
            } else {
                $payment->setStatus(Movement::STATUS_PENDING);
                $payment->setAutoConfirmAt($autoConfirmAt);
            }
            $this->em->persist($payment);
        }

        $this->em->flush();

        return new JsonResponse([
            'batchId'          => $batchId,
            'confirmationMode' => $settings->confirmationMode,
            'autoConfirmAt'    => $autoConfirmAt?->format(\DateTimeInterface::RFC3339),
            'allocations'      => $this->serializeAllocations($allocations, $result['memberNames']),
        ], 201);
    }

    #[Route('/payments/{batchId}', methods: ['GET'])]
    public function batch(string $batchId): JsonResponse
    {
        $movements = $this->em->createQuery(
            'SELECT m FROM App\Entity\Movement m WHERE m.batchId = :batchId ORDER BY m.createdAt ASC'
        )
        ->setParameter('batchId', $batchId)
        ->getResult();

        if (empty($movements)) {
            return new JsonResponse(['error' => 'Batch not found.'], 404);
        }

        /** @var Member $member */
        $member = $this->getUser();
        if (!$this->projectGuard->canView($member, $movements[0]->getProject())) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        return new JsonResponse(array_map(fn(Movement $m) => [
            'id'           => $m->getId(),
            'type'         => $m->getType(),
            'status'       => $m->getStatus(),
            'fromMemberId' => $m->getFromMember() !== null ? (int) $m->getFromMember()->getId() : null,
            'toMemberId'   => $m->getToMember() !== null ? (int) $m->getToMember()->getId() : null,
            'amount'       => $m->getAmount(),
            'createdAt'    => $m->getCreatedAt()->format(\DateTimeInterface::RFC3339),
        ], $movements));
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
             AND m.status = :pending
             AND NOT EXISTS (SELECT c.id FROM App\Entity\Movement c WHERE c.cancelsMovement = m)'
        )
        ->setParameter('batchId', $batchId)
        ->setParameter('member', $member)
        ->setParameter('pending', Movement::STATUS_PENDING)
        ->getResult();

        if (empty($payments)) {
            return new JsonResponse(['error' => 'No pending payments found for this batch.'], 404);
        }

        $now = new \DateTimeImmutable();
        foreach ($payments as $payment) {
            $payment->confirm($member, 'receiver', $now);
        }

        $this->em->flush();

        return new JsonResponse(['confirmed' => count($payments)]);
    }

    // Payer takes back their pending shares (or fixes an optimistic payment
    // within the self-cancel window). Shares already confirmed stay.
    #[Route('/payments/{batchId}/withdraw', methods: ['POST'])]
    public function withdraw(string $batchId): JsonResponse
    {
        return $this->cancelOwnShares(
            $batchId,
            'fromMember',
            CancellationPolicy::ACTION_WITHDRAW,
            'Retirado por el pagador.',
            'No hay partes de este pago que puedas retirar (ya fueron confirmadas o venció el plazo).',
        );
    }

    // Receiver rejects their own share ("no me llegó"); the other shares stay.
    #[Route('/payments/{batchId}/reject', methods: ['POST'])]
    public function reject(string $batchId, Request $request): JsonResponse
    {
        $body   = json_decode($request->getContent(), true) ?? [];
        $reason = is_string($body['reason'] ?? null) && trim($body['reason']) !== ''
            ? trim($body['reason'])
            : 'Rechazado por el receptor.';

        return $this->cancelOwnShares(
            $batchId,
            'toMember',
            CancellationPolicy::ACTION_REJECT,
            $reason,
            'No hay partes de este pago que puedas rechazar (ya las confirmaste o venció el plazo de objeción).',
        );
    }

    private function cancelOwnShares(string $batchId, string $side, string $action, string $reason, string $nothingMessage): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        try {
            return $this->em->wrapInTransaction(function () use ($batchId, $side, $action, $reason, $nothingMessage, $member) {
                $shares = $this->em->createQuery(
                    "SELECT m FROM App\Entity\Movement m
                     WHERE m.batchId = :batchId AND m.type = :payment AND m.{$side} = :member"
                )
                ->setParameter('batchId', $batchId)
                ->setParameter('payment', Movement::TYPE_PAYMENT)
                ->setParameter('member', $member)
                ->getResult();

                if (empty($shares)) {
                    return new JsonResponse(['error' => 'Batch not found.'], 404);
                }

                $count = $this->userCancellation->cancel($shares[0]->getProject(), $shares, $member, $action, $reason);
                if ($count === 0) {
                    return new JsonResponse(['error' => $nothingMessage], 409);
                }

                return new JsonResponse(['batchId' => $batchId, 'cancelled' => $count]);
            });
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['error' => 'Ese pago ya fue anulado.'], 409);
        }
    }

    /** @return JsonResponse|array{allocations: Allocation[], memberNames: array<int,string>, project: Project, member: Member} */
    private function resolveAllocation(int $projectId, Request $request, bool $lock = false): JsonResponse|array
    {
        /** @var Member $member */
        $member = $this->getUser();

        $project = $this->em->find(Project::class, $projectId, $lock ? LockMode::PESSIMISTIC_WRITE : null);
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

        if (!$this->projectGuard->isActive($project)) {
            return new JsonResponse(
                ['error' => 'El proyecto no está activo: los porcentajes de los socios deben sumar 100% antes de registrar movimientos.'],
                409
            );
        }

        $body   = json_decode($request->getContent(), true) ?? [];
        $amount = $this->decimals->parse($body['amount'] ?? null, 16, 2);

        if ($amount === null || bccomp($amount, '0', 2) <= 0) {
            return new JsonResponse(['error' => 'amount must be a positive number with at most 2 decimals.'], 400);
        }

        $movements = $this->movementLoader->forProject($project);
        $shares    = $this->shareLoader->forProject($project);
        $balances  = $this->calculator->calculate($movements, $shares, includePending: true);
        $memberId  = (int) $member->getId();

        $confirmed = $this->calculator->calculate($movements, $shares)[$memberId] ?? '0.00';
        if (bccomp($confirmed, '0', 2) < 0 && bccomp($balances[$memberId] ?? '0.00', '0', 2) >= 0) {
            return new JsonResponse(
                ['error' => 'Tu deuda ya está cubierta por pagos pendientes de confirmación.'],
                422
            );
        }

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
