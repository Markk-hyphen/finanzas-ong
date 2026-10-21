<?php

declare(strict_types=1);

namespace App\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\Transport\Dsn;

// Which delivery channels this instance offers (MANIFIESTO E3). Like
// auto_timeout, it is an instance capability: it depends on the deployment's
// configuration, not on any project or member setting.
class NotificationChannels
{
    public function __construct(
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        #[Autowire('%env(TELEGRAM_DSN)%')] private readonly string $telegramDsn,
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')] private readonly string $telegramBotUsername,
    ) {}

    public function emailEnabled(): bool
    {
        return !str_starts_with($this->mailerDsn, 'null://');
    }

    public function telegramEnabled(): bool
    {
        return !str_starts_with($this->telegramDsn, 'null://') && $this->telegramBotUsername !== '';
    }

    public function telegramBotUsername(): string
    {
        return ltrim($this->telegramBotUsername, '@');
    }

    public function telegramBotToken(): string
    {
        // A bot token is "<id>:<secret>", so the DSN parser splits it into user and password
        $dsn = new Dsn($this->telegramDsn);

        return $dsn->getUser().':'.$dsn->getPassword();
    }
}
