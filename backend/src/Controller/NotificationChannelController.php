<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\NotificationChannels;
use App\Application\TelegramLinker;
use App\Entity\Member;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

#[Route('/api/me')]
class NotificationChannelController extends AbstractController
{
    public function __construct(
        private readonly NotificationChannels $channels,
        private readonly TelegramLinker $telegram,
    ) {}

    #[Route('/notification-channels', methods: ['GET'])]
    public function channels(): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        return new JsonResponse($this->describe($member));
    }

    #[Route('/telegram/link', methods: ['POST'])]
    public function link(): JsonResponse
    {
        if (!$this->channels->telegramEnabled()) {
            return new JsonResponse(['error' => 'Telegram is not available on this instance.'], 409);
        }

        /** @var Member $member */
        $member = $this->getUser();

        return new JsonResponse(['link' => $this->telegram->start($member)]);
    }

    #[Route('/telegram/check', methods: ['POST'])]
    public function check(): JsonResponse
    {
        if (!$this->channels->telegramEnabled()) {
            return new JsonResponse(['error' => 'Telegram is not available on this instance.'], 409);
        }

        /** @var Member $member */
        $member = $this->getUser();

        try {
            $this->telegram->processPendingStarts();
        } catch (HttpClientException) {
            return new JsonResponse(['error' => 'Could not reach Telegram. Try again in a moment.'], 502);
        }

        return new JsonResponse($this->describe($member));
    }

    #[Route('/telegram', methods: ['DELETE'])]
    public function unlink(): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();
        $this->telegram->unlink($member);

        return new JsonResponse($this->describe($member));
    }

    private function describe(Member $member): array
    {
        return [
            'email' => [
                'enabled' => $this->channels->emailEnabled(),
                'address' => $member->getEmail(),
            ],
            'telegram' => [
                'enabled'     => $this->channels->telegramEnabled(),
                'linked'      => $member->isTelegramLinked(),
            ],
        ];
    }
}
