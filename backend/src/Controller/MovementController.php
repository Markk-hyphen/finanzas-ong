<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\ActivityRecorder;
use App\Domain\Model\ActivityEvent;
use App\Domain\Service\CancellationPolicy;
use App\Entity\Member;
use App\Entity\Movement;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class MovementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActivityRecorder $activityRecorder,
    ) {}

    #[Route('/movements/{id}', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        $movement = $this->em->find(Movement::class, $id);
        if (!$movement) {
            return new JsonResponse(['error' => 'Movement not found.'], 404);
        }

        $membership = $this->em->createQuery(
            'SELECT mp FROM App\Entity\MemberProject mp WHERE mp.member = :member AND mp.project = :project'
        )
        ->setParameter('member', $member)
        ->setParameter('project', $movement->getProject())
        ->getOneOrNullResult();

        if (!$membership) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        return new JsonResponse([
            'id'           => $movement->getId(),
            'type'         => $movement->getType(),
            'status'       => $movement->getStatus(),
            'amount'       => $movement->getAmount(),
            'fromMemberId' => $movement->getFromMember() !== null ? (int) $movement->getFromMember()->getId() : null,
            'toMemberId'   => $movement->getToMember() !== null ? (int) $movement->getToMember()->getId() : null,
            'batchId'      => $movement->getBatchId(),
            'material'     => $movement->getMaterial()?->getName(),
            'quantity'     => $movement->getQuantity(),
            'reason'       => $movement->getReason(),
            'createdById'  => (int) $movement->getCreatedBy()->getId(),
            'createdAt'    => $movement->getCreatedAt()->format(\DateTimeInterface::RFC3339),
        ]);
    }

    #[Route('/movements/{id}/cancel', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cancel(int $id, Request $request): JsonResponse
    {
        try {
            return $this->em->wrapInTransaction(fn() => $this->persistCancellation($id, $request));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request cancelled the same movement first.
            return new JsonResponse(['error' => 'Movement is already cancelled.'], 409);
        }
    }

    private function persistCancellation(int $id, Request $request): JsonResponse
    {
        /** @var Member $admin */
        $admin = $this->getUser();

        $movement = $this->em->find(Movement::class, $id);
        if (!$movement) {
            return new JsonResponse(['error' => 'Movement not found.'], 404);
        }

        // Same per-project lock as payment creation: ledger writes are serialized.
        $this->em->lock($movement->getProject(), LockMode::PESSIMISTIC_WRITE);

        if ($movement->getType() === Movement::TYPE_CANCELLATION) {
            return new JsonResponse(['error' => 'Cannot cancel a cancellation.'], 422);
        }

        $alreadyCancelled = $this->em->createQuery(
            'SELECT m FROM App\Entity\Movement m WHERE m.cancelsMovement = :movement'
        )
        ->setParameter('movement', $movement)
        ->getOneOrNullResult();

        if ($alreadyCancelled !== null) {
            return new JsonResponse(['error' => 'Movement is already cancelled.'], 409);
        }

        $body   = json_decode($request->getContent(), true) ?? [];
        $reason = trim((string) ($body['reason'] ?? ''));

        if ($reason === '') {
            return new JsonResponse(['error' => 'reason is required.'], 400);
        }

        $batchId = $movement->getBatchId();

        if ($batchId !== null) {
            // Cancel all active movements in the batch (rows already cancelled on their own are skipped)
            $batchMovements = $this->em->createQuery(
                'SELECT m FROM App\Entity\Movement m
                 WHERE m.batchId = :batchId AND m.type != :cancel
                 AND NOT EXISTS (SELECT c.id FROM App\Entity\Movement c WHERE c.cancelsMovement = m)'
            )
            ->setParameter('batchId', $batchId)
            ->setParameter('cancel', Movement::TYPE_CANCELLATION)
            ->getResult();

            foreach ($batchMovements as $bm) {
                $cancellation = $this->buildCancellation($bm, $admin, $reason);
                $this->em->persist($cancellation);
                $this->activityRecorder->record($bm->getProject(), ActivityEvent::MOVEMENT_CANCELLED_ADMIN, $cancellation, $admin);
            }

            $this->em->flush();

            return new JsonResponse(['cancelledBatch' => $batchId, 'count' => count($batchMovements)]);
        }

        $cancellation = $this->buildCancellation($movement, $admin, $reason);
        $this->em->persist($cancellation);
        $this->activityRecorder->record($movement->getProject(), ActivityEvent::MOVEMENT_CANCELLED_ADMIN, $cancellation, $admin);
        $this->em->flush();

        return new JsonResponse(['cancellationId' => $cancellation->getId()]);
    }

    private function buildCancellation(Movement $target, Member $admin, string $reason): Movement
    {
        return Movement::cancellationOf($target, $admin, CancellationPolicy::KIND_ADMIN, $reason);
    }
}
