<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'movements')]
// A movement can be cancelled at most once; enforced in the DB so concurrent
// cancel requests cannot insert duplicate cancellation rows.
#[ORM\UniqueConstraint(name: 'uniq_movements_cancels_movement', columns: ['cancels_movement_id'])]
class Movement
{
    public const TYPE_EXPENSE     = 'expense';
    public const TYPE_PAYMENT     = 'payment';
    public const TYPE_CANCELLATION = 'cancellation';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: false)]
    private Project $project;

    #[ORM\Column(type: 'text')]
    private string $type;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'from_member_id', nullable: true)]
    private ?Member $fromMember = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'to_member_id', nullable: true)]
    private ?Member $toMember = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(type: 'text')]
    private string $status = self::STATUS_CONFIRMED;

    #[ORM\Column(name: 'batch_id', type: 'guid', nullable: true)]
    private ?string $batchId = null;

    // Ledger is append-only: errors are corrected by inserting a cancellation row that
    // points to the original, never by updating or deleting the original movement.
    #[ORM\ManyToOne(targetEntity: Movement::class)]
    #[ORM\JoinColumn(name: 'cancels_movement_id', nullable: true)]
    private ?Movement $cancelsMovement = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    // Cancellations only: admin | withdrawal | self_correction | rejection
    #[ORM\Column(name: 'cancellation_kind', type: 'text', nullable: true)]
    private ?string $cancellationKind = null;

    // Payments only: who/when/how the payment became confirmed
    // (confirmedBy is null for optimistic and timeout confirmations).
    #[ORM\Column(name: 'confirmed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'confirmed_by_id', nullable: true)]
    private ?Member $confirmedBy = null;

    #[ORM\Column(name: 'confirmed_via', type: 'text', nullable: true)]
    private ?string $confirmedVia = null;

    // auto_timeout payments: frozen at creation, the scheduler confirms after it
    #[ORM\Column(name: 'auto_confirm_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $autoConfirmAt = null;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', nullable: true)]
    private ?Material $material = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 3, nullable: true)]
    private ?string $quantity = null;

    #[ORM\Column(name: 'reference_value', type: 'decimal', precision: 18, scale: 4, nullable: true)]
    private ?string $referenceValue = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private Member $createdBy;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, string $type, Member $createdBy)
    {
        $this->project   = $project;
        $this->type      = $type;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getType(): string { return $this->type; }
    public function getFromMember(): ?Member { return $this->fromMember; }
    public function setFromMember(?Member $member): void { $this->fromMember = $member; }
    public function getToMember(): ?Member { return $this->toMember; }
    public function setToMember(?Member $member): void { $this->toMember = $member; }
    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(?string $amount): void { $this->amount = $amount; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getBatchId(): ?string { return $this->batchId; }
    public function setBatchId(?string $batchId): void { $this->batchId = $batchId; }
    public function getCancelsMovement(): ?Movement { return $this->cancelsMovement; }
    public function setCancelsMovement(?Movement $movement): void { $this->cancelsMovement = $movement; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): void { $this->reason = $reason; }
    public function getMaterial(): ?Material { return $this->material; }
    public function setMaterial(?Material $material): void { $this->material = $material; }
    public function getQuantity(): ?string { return $this->quantity; }
    public function setQuantity(?string $quantity): void { $this->quantity = $quantity; }
    public function getReferenceValue(): ?string { return $this->referenceValue; }
    public function setReferenceValue(?string $value): void { $this->referenceValue = $value; }
    public function getCancellationKind(): ?string { return $this->cancellationKind; }
    public function setCancellationKind(?string $kind): void { $this->cancellationKind = $kind; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function getConfirmedBy(): ?Member { return $this->confirmedBy; }
    public function getConfirmedVia(): ?string { return $this->confirmedVia; }
    public function getAutoConfirmAt(): ?\DateTimeImmutable { return $this->autoConfirmAt; }
    public function setAutoConfirmAt(?\DateTimeImmutable $at): void { $this->autoConfirmAt = $at; }

    public static function cancellationOf(Movement $target, Member $author, string $kind, string $reason): self
    {
        $cancellation = new self($target->getProject(), self::TYPE_CANCELLATION, $author);
        $cancellation->setCancelsMovement($target);
        $cancellation->setCancellationKind($kind);
        $cancellation->setReason($reason);
        return $cancellation;
    }

    public function confirm(?Member $by, string $via, \DateTimeImmutable $at): void
    {
        $this->status       = self::STATUS_CONFIRMED;
        $this->confirmedBy  = $by;
        $this->confirmedVia = $via;
        $this->confirmedAt  = $at;
    }

    public function getCreatedBy(): Member { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
