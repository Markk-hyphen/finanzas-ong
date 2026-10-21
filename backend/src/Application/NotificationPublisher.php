<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\Notification;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

// Thin wrapper around the Mercure hub, scoped to one topic shape: a member's
// own notification feed. The hub/factory come from the "default" hub already
// configured in config/packages/mercure.yaml — no separate subscriber secret.
//
// Pushes are queued and sent on kernel/console terminate, i.e. after the
// ledger transaction is over: a slow or down hub must never hold the project
// lock or roll back a payment. A push for a rolled-back write is harmless —
// it only makes the client re-read its notifications from the DB.
class NotificationPublisher implements EventSubscriberInterface
{
    /** @var Notification[] */
    private array $queue = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly TokenFactoryInterface $defaultFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE   => 'publishQueued',
            ConsoleEvents::TERMINATE  => 'publishQueued',
        ];
    }

    public function queue(Notification $notification): void
    {
        $this->queue[] = $notification;
    }

    public function publishQueued(): void
    {
        $queue       = $this->queue;
        $this->queue = [];

        foreach ($queue as $notification) {
            if ($notification->getId() === null) {
                continue; // never flushed
            }

            $recipientId = (int) $notification->getRecipient()->getId();
            try {
                $this->hub->publish(new Update(
                    topics: $this->topicFor($recipientId),
                    data: json_encode(['id' => (int) $notification->getId(), 'eventType' => $notification->getEventType()], JSON_THROW_ON_ERROR),
                    private: true,
                ));
            } catch (\Throwable $e) {
                $this->logger->warning('Mercure push failed; notification still reachable by polling.', [
                    'notificationId' => $notification->getId(),
                    'exception'      => $e,
                ]);
            }
        }
    }

    /** JWT a member's own browser uses to subscribe to their topic — never shared between members. */
    public function subscriberTokenFor(int $memberId): string
    {
        return $this->defaultFactory->create(subscribe: [$this->topicFor($memberId)]);
    }

    private function topicFor(int $memberId): string
    {
        return "https://finanzas-ong.app/members/{$memberId}/notifications";
    }
}
