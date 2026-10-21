<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Service\DeliveryBackoff;
use App\Domain\Service\NotificationMessage;
use App\Entity\Notification;
use App\Entity\NotificationDelivery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Transport\TransportInterface as MailerTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Exception\TransportException as ChatTransportException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Transport\TransportInterface as ChatTransport;

// Mail/Telegram delivery of notifications through the notification_delivery
// outbox (docs/fase3-mail-telegram.md §2.3). Rows are created inside the ledger
// transaction; sending happens on kernel/console terminate (fast path) and in
// app:notifications:deliver (retries), never while the project lock is held.
// Both paths claim a row with a conditional UPDATE first, so a row is sent once.
class NotificationDeliverer implements EventSubscriberInterface
{
    // A row stuck in "sending" this long belongs to a process that died mid-send.
    private const STALE_CLAIM = '-10 minutes';

    /** @var NotificationDelivery[] */
    private array $queue = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerTransport $mailer,
        #[Autowire(service: 'chatter.transports')] private readonly ChatTransport $chatter,
        private readonly NotificationChannels $channels,
        private readonly DeliveryBackoff $backoff,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $mailFrom,
        #[Autowire('%env(APP_PUBLIC_URL)%')] private readonly string $publicUrl,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE  => 'deliverQueued',
            ConsoleEvents::TERMINATE => 'deliverQueued',
        ];
    }

    /** Called inside the ledger transaction: only persists, never sends. */
    public function createFor(Notification $notification): void
    {
        $recipient = $notification->getRecipient();
        if (!$recipient->isActive()) {
            return;
        }

        $now = new \DateTimeImmutable();
        if ($this->channels->emailEnabled()) {
            $this->enqueue(new NotificationDelivery($notification, NotificationDelivery::CHANNEL_EMAIL, $now));
        }
        if ($this->channels->telegramEnabled() && $recipient->isTelegramLinked()) {
            $this->enqueue(new NotificationDelivery($notification, NotificationDelivery::CHANNEL_TELEGRAM, $now));
        }
    }

    public function deliverQueued(): void
    {
        $queue       = $this->queue;
        $this->queue = [];

        foreach ($queue as $delivery) {
            if ($delivery->getId() !== null) {
                $this->attemptSafely($delivery->getId());
            }
        }
    }

    /** @return int deliveries attempted */
    public function deliverDue(int $limit = 100): int
    {
        $now = new \DateTimeImmutable();
        $ids = $this->em->createQuery(
            'SELECT d.id FROM App\Entity\NotificationDelivery d
             WHERE (d.status = :pending AND d.nextAttemptAt <= :now)
                OR (d.status = :sending AND d.claimedAt < :stale)
             ORDER BY d.id'
        )
        ->setParameter('pending', NotificationDelivery::STATUS_PENDING)
        ->setParameter('sending', NotificationDelivery::STATUS_SENDING)
        ->setParameter('now', $now)
        ->setParameter('stale', $now->modify(self::STALE_CLAIM))
        ->setMaxResults($limit)
        ->getSingleColumnResult();

        foreach ($ids as $id) {
            $this->attemptSafely((int) $id);
        }

        return count($ids);
    }

    private function enqueue(NotificationDelivery $delivery): void
    {
        $this->em->persist($delivery);
        $this->queue[] = $delivery;
    }

    private function attemptSafely(int $id): void
    {
        try {
            $this->attempt($id);
        } catch (\Throwable $e) {
            // e.g. the EntityManager was closed by a rolled-back request; the sweep retries.
            $this->logger->warning('Notification delivery attempt aborted.', ['deliveryId' => $id, 'exception' => $e]);
        }
    }

    private function attempt(int $id): void
    {
        $now     = new \DateTimeImmutable();
        $claimed = $this->em->createQuery(
            'UPDATE App\Entity\NotificationDelivery d
             SET d.status = :sending, d.claimedAt = :now, d.attempts = d.attempts + 1
             WHERE d.id = :id
               AND ((d.status = :pending AND d.nextAttemptAt <= :now)
                 OR (d.status = :sending AND d.claimedAt < :stale))'
        )
        ->setParameter('id', $id)
        ->setParameter('pending', NotificationDelivery::STATUS_PENDING)
        ->setParameter('sending', NotificationDelivery::STATUS_SENDING)
        ->setParameter('now', $now)
        ->setParameter('stale', $now->modify(self::STALE_CLAIM))
        ->execute();

        if ($claimed === 0) {
            return; // already taken by the other path, or the write was rolled back
        }

        $delivery = $this->em->find(NotificationDelivery::class, $id);
        $this->em->refresh($delivery);

        try {
            $this->send($delivery);
        } catch (TelegramBlocked) {
            $delivery->getNotification()->getRecipient()->unlinkTelegram();
            $delivery->markSkipped('Telegram 403: the member blocked the bot; unlinked.');
        } catch (\Throwable $e) {
            $error = substr($e->getMessage(), 0, 1000);
            $next  = $this->backoff->nextAttemptAt($delivery->getAttempts(), $now);
            $next !== null ? $delivery->markRetry($error, $next) : $delivery->markFailed($error);
            $this->logger->warning('Notification delivery failed.', ['deliveryId' => $id, 'attempt' => $delivery->getAttempts(), 'exception' => $e]);
        }

        $this->em->flush();
    }

    private function send(NotificationDelivery $delivery): void
    {
        $notification = $delivery->getNotification();
        $recipient    = $notification->getRecipient();

        if (!$recipient->isActive()) {
            $delivery->markSkipped('Recipient deactivated before delivery.');
            return;
        }
        if ($delivery->getChannel() === NotificationDelivery::CHANNEL_TELEGRAM && !$recipient->isTelegramLinked()) {
            $delivery->markSkipped('Recipient unlinked Telegram before delivery.');
            return;
        }

        $project = $notification->getMovement()?->getProject();
        $message = NotificationMessage::for(
            $notification->getEventType(),
            $recipient->getName(),
            $project?->getName() ?? 'tu proyecto',
            rtrim($this->publicUrl, '/') . ($project !== null ? "/#/project/{$project->getId()}" : '/#/projects'),
        );

        if ($delivery->getChannel() === NotificationDelivery::CHANNEL_EMAIL) {
            $this->mailer->send(
                (new Email())
                    ->from($this->mailFrom)
                    ->to($recipient->getEmail())
                    ->subject($message->subject)
                    ->text($message->body)
            );
        } else {
            $this->sendTelegram((string) $recipient->getTelegramChatId(), $message->body);
        }

        $delivery->markSent(new \DateTimeImmutable());
    }

    private function sendTelegram(string $chatId, string $text): void
    {
        $options = (new TelegramOptions())->chatId($chatId)->disableWebPagePreview(true);

        try {
            $this->chatter->send((new ChatMessage($text, $options))->transport('telegram'));
        } catch (ChatTransportException $e) {
            if ($e->getResponse()->getStatusCode() === 403) {
                throw new TelegramBlocked();
            }
            throw $e;
        }
    }
}
