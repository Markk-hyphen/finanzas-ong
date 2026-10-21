<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

// Full, append-only history of what happened in a project. Written for every
// ledger-affecting event, regardless of whether anyone gets notified about it
// (see Notification for the targeted, "you should look at this" subset).
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: false)]
    private Project $project;

    #[ORM\Column(name: 'event_type', type: 'text')]
    private string $eventType;

    #[ORM\ManyToOne(targetEntity: Movement::class)]
    #[ORM\JoinColumn(name: 'movement_id', nullable: true)]
    private ?Movement $movement = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true)]
    private ?Member $actor = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, string $eventType, ?Movement $movement, ?Member $actor, ?array $payload = null)
    {
        $this->project    = $project;
        $this->eventType  = $eventType;
        $this->movement   = $movement;
        $this->actor      = $actor;
        $this->payload    = $payload;
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getEventType(): string { return $this->eventType; }
    public function getMovement(): ?Movement { return $this->movement; }
    public function getActor(): ?Member { return $this->actor; }
    public function getPayload(): ?array { return $this->payload; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
