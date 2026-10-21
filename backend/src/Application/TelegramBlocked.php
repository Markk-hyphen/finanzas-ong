<?php

declare(strict_types=1);

namespace App\Application;

// Telegram answered 403: the member blocked the bot, so retrying is pointless.
final class TelegramBlocked extends \RuntimeException {}
