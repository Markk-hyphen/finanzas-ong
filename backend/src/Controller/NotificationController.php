<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\NotificationPublisher;
use App\Entity\Member;
use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notifications')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationPublisher $publisher,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        $notifications = $this->em->createQuery(
            'SELECT n FROM App\Entity\Notification n WHERE n.recipient = :member ORDER BY n.createdAt DESC'
        )
        ->setParameter('member', $member)
        ->setMaxResults(50)
        ->getResult();

        $unreadCount = $this->em->createQuery(
            'SELECT COUNT(n.id) FROM App\Entity\Notification n WHERE n.recipient = :member AND n.readAt IS NULL'
        )
        ->setParameter('member', $member)
        ->getSingleScalarResult();

        return new JsonResponse([
            'unreadCount'   => (int) $unreadCount,
            'notifications' => array_map(fn(Notification $n) => [
                'id'         => $n->getId(),
                'eventType'  => $n->getEventType(),
                'movementId' => $n->getMovement()?->getId(),
                'projectId'  => $n->getMovement()?->getProject()->getId(),
                'read'       => $n->getReadAt() !== null,
                'createdAt'  => $n->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            ], $notifications),
        ]);
    }

    #[Route('/{id}/read', methods: ['POST'])]
    public function markRead(int $id): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        $notification = $this->em->find(Notification::class, $id);
        if ($notification === null || (int) $notification->getRecipient()->getId() !== (int) $member->getId()) {
            return new JsonResponse(['error' => 'Notification not found.'], 404);
        }

        $notification->markRead(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse(['id' => $id, 'read' => true]);
    }

    // Short-lived JWT that lets this member's browser subscribe to their own
    // Mercure topic — minted per session, never the hub's shared secret.
    #[Route('/subscription-token', methods: ['GET'])]
    public function subscriptionToken(): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        return new JsonResponse(['token' => $this->publisher->subscriberTokenFor((int) $member->getId())]);
    }
}
