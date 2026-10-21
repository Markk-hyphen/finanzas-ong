<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

// Targeted "you should look at this" entry for one recipient. Created from
// the same events as AuditLog, but only for the subset NotificationTargets
// decides matters to that specific member. The DB row is the source of
// truth; Mercure only pushes a live hint that one was created (see
// NotificationPublisher).
#[ORM\Entity]
#[ORM\Table(name: 'notification')]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'recipient_id', nullable: false)]
    private Member $recipient;

    #[ORM\Column(name: 'event_type', type: 'text')]
    private string $eventType;

    #[ORM\ManyToOne(targetEntity: Movement::class)]
    #[ORM\JoinColumn(name: 'movement_id', nullable: true)]
    private ?Movement $movement = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(name: 'read_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Member $recipient, string $eventType, ?Movement $movement, ?array $payload = null)
    {
        $this->recipient = $recipient;
        $this->eventType = $eventType;
        $this->movement  = $movement;
        $this->payload   = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function markRead(\DateTimeImmutable $at): void
    {
        $this->readAt ??= $at;
    }

    public function getId(): ?int { return $this->id; }
    public function getRecipient(): Member { return $this->recipient; }
    public function getEventType(): string { return $this->eventType; }
    public function getMovement(): ?Movement { return $this->movement; }
    public function getPayload(): ?array { return $this->payload; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
