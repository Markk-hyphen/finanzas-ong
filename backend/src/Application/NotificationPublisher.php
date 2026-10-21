<?php

declare(strict_types=1);

namespace App\Application;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

// Thin wrapper around the Mercure hub, scoped to one topic shape: a member's
// own notification feed. The hub/factory come from the "default" hub already
// configured in config/packages/mercure.yaml — no separate subscriber secret.
class NotificationPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly TokenFactoryInterface $defaultFactory,
    ) {}

    public function publishCreated(int $recipientId, int $notificationId, string $eventType): void
    {
        $this->hub->publish(new Update(
            topics: $this->topicFor($recipientId),
            data: json_encode(['id' => $notificationId, 'eventType' => $eventType], JSON_THROW_ON_ERROR),
            private: true,
        ));
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
