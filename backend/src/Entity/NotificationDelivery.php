<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

// Durable outbox row: one per notification and channel (MANIFIESTO E3). Written
// in the ledger transaction, sent after it (NotificationDeliverer) and retried
// by app:notifications:deliver.
#[ORM\Entity]
#[ORM\Table(name: 'notification_delivery')]
#[ORM\UniqueConstraint(name: 'uniq_notification_delivery_channel', columns: ['notification_id', 'channel'])]
#[ORM\Index(name: 'idx_notification_delivery_due', columns: ['status', 'next_attempt_at'])]
class NotificationDelivery
{
    public const CHANNEL_EMAIL    = 'email';
    public const CHANNEL_TELEGRAM = 'telegram';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Notification::class)]
    #[ORM\JoinColumn(name: 'notification_id', nullable: false)]
    private Notification $notification;

    #[ORM\Column(type: 'text')]
    private string $channel;

    #[ORM\Column(type: 'text')]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(name: 'next_attempt_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $nextAttemptAt;

    #[ORM\Column(name: 'claimed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'sent_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(Notification $notification, string $channel, \DateTimeImmutable $now)
    {
        $this->notification  = $notification;
        $this->channel       = $channel;
        $this->nextAttemptAt = $now;
    }

    public function markSent(\DateTimeImmutable $at): void
    {
        $this->status    = self::STATUS_SENT;
        $this->sentAt    = $at;
        $this->claimedAt = null;
        $this->lastError = null;
    }

    public function markRetry(string $error, \DateTimeImmutable $nextAttemptAt): void
    {
        $this->status        = self::STATUS_PENDING;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->claimedAt     = null;
        $this->lastError     = $error;
    }

    public function markFailed(string $error): void
    {
        $this->status    = self::STATUS_FAILED;
        $this->claimedAt = null;
        $this->lastError = $error;
    }

    public function markSkipped(string $reason): void
    {
        $this->status    = self::STATUS_SKIPPED;
        $this->claimedAt = null;
        $this->lastError = $reason;
    }

    public function getId(): ?int { return $this->id; }
    public function getNotification(): Notification { return $this->notification; }
    public function getChannel(): string { return $this->channel; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getNextAttemptAt(): \DateTimeImmutable { return $this->nextAttemptAt; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
}
