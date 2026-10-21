<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Transport\TransportInterface as ChatTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Links a member to their Telegram chat without a webhook (docs/fase3-mail-telegram.md §2.4):
// the member opens t.me/<bot>?start=<token>, then the app reads pending /start
// messages with getUpdates on demand and matches them against stored token hashes.
class TelegramLinker
{
    private const TOKEN_TTL = '+15 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
        private readonly NotificationChannels $channels,
        #[Autowire(service: 'chatter.transports')] private readonly ChatTransport $chatter,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return string deep link the member opens to start the bot */
    public function start(Member $member): string
    {
        $token = bin2hex(random_bytes(32));
        $member->startTelegramLink(hash('sha256', $token), (new \DateTimeImmutable())->modify(self::TOKEN_TTL));
        $this->em->flush();

        return sprintf('https://t.me/%s?start=%s', $this->channels->telegramBotUsername(), $token);
    }

    /**
     * Processes every pending /start (also other members'), then acknowledges
     * them so Telegram does not return them again. Idempotent: a used token is cleared.
     */
    public function processPendingStarts(): void
    {
        $updates = $this->api('getUpdates', ['timeout' => 0, 'allowed_updates' => '["message"]'])['result'] ?? [];
        if ($updates === []) {
            return;
        }

        $now = new \DateTimeImmutable();
        foreach ($updates as $update) {
            $message = $update['message'] ?? null;
            if (($message['chat']['type'] ?? null) !== 'private'
                || !preg_match('/^\/start ([a-f0-9]{64})$/', (string) ($message['text'] ?? ''), $m)) {
                continue;
            }

            $chatId = (string) $message['chat']['id'];
            $member = $this->em->createQuery(
                'SELECT m FROM App\Entity\Member m
                 WHERE m.telegramLinkTokenHash = :hash AND m.telegramLinkExpiresAt > :now'
            )
            ->setParameter('hash', hash('sha256', $m[1]))
            ->setParameter('now', $now)
            ->getOneOrNullResult();

            if ($member instanceof Member) {
                $member->completeTelegramLink($chatId);
                $this->reply($chatId, "Listo, {$member->getName()}: vas a recibir acá los avisos de Finanzas ONG.");
            } else {
                $this->reply($chatId, 'Este link venció o ya se usó. Generá uno nuevo desde "Mi cuenta" en la app.');
            }
        }

        $this->em->flush();

        $lastId = max(array_column($updates, 'update_id'));
        $this->api('getUpdates', ['offset' => $lastId + 1, 'limit' => 1, 'timeout' => 0]);
    }

    public function unlink(Member $member): void
    {
        $member->unlinkTelegram();
        $this->em->flush();
    }

    private function api(string $method, array $query): array
    {
        return $this->http->request(
            'GET',
            sprintf('https://api.telegram.org/bot%s/%s', $this->channels->telegramBotToken(), $method),
            ['query' => $query, 'timeout' => 10],
        )->toArray();
    }

    private function reply(string $chatId, string $text): void
    {
        try {
            $this->chatter->send((new ChatMessage($text, (new TelegramOptions())->chatId($chatId)))->transport('telegram'));
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram link confirmation could not be sent.', ['exception' => $e]);
        }
    }
}
