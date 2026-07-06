<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use App\Entity\Movement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class MovementController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

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
        /** @var Member $admin */
        $admin = $this->getUser();

        $movement = $this->em->find(Movement::class, $id);
        if (!$movement) {
            return new JsonResponse(['error' => 'Movement not found.'], 404);
        }

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
            // Cancel all active movements in the batch
            $batchMovements = $this->em->createQuery(
                'SELECT m FROM App\Entity\Movement m WHERE m.batchId = :batchId AND m.type != :cancel'
            )
            ->setParameter('batchId', $batchId)
            ->setParameter('cancel', Movement::TYPE_CANCELLATION)
            ->getResult();

            foreach ($batchMovements as $bm) {
                $this->em->persist($this->buildCancellation($bm, $admin, $reason));
            }

            $this->em->flush();

            return new JsonResponse(['cancelledBatch' => $batchId, 'count' => count($batchMovements)]);
        }

        $cancellation = $this->buildCancellation($movement, $admin, $reason);
        $this->em->persist($cancellation);
        $this->em->flush();

        return new JsonResponse(['cancellationId' => $cancellation->getId()]);
    }

    private function buildCancellation(Movement $target, Member $admin, string $reason): Movement
    {
        $cancellation = new Movement($target->getProject(), Movement::TYPE_CANCELLATION, $admin);
        $cancellation->setCancelsMovement($target);
        $cancellation->setReason($reason);
        return $cancellation;
    }
}
