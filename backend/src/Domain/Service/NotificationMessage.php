<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\ActivityEvent;

/**
 * Text of the mail/Telegram message for a notification. Deliberately carries
 * no amounts or counterparts (MANIFIESTO E3): it travels through third-party
 * providers, so it only says what kind of event happened, where, and links back.
 */
final class NotificationMessage
{
    private const TEXTS = [
        ActivityEvent::PAYMENT_CREATED_OPTIMISTIC => [
            'Tenés un pago nuevo',
            'Te registraron un pago en «%s». Ya cuenta como confirmado: si no te llegó, podés rechazarlo dentro del plazo.',
        ],
        ActivityEvent::PAYMENT_CREATED_AUTO_TIMEOUT => [
            'Tenés un pago pendiente',
            'Tenés un pago pendiente en «%s». Si no lo rechazás antes del plazo, se confirma solo.',
        ],
        ActivityEvent::PAYMENT_AUTO_CONFIRMED => [
            'Un pago se confirmó solo',
            'Venció el plazo sin objeciones y un pago en «%s» quedó confirmado.',
        ],
        ActivityEvent::PAYMENT_WITHDRAWN => [
            'Retiraron un pago que esperabas',
            'El pagador retiró un pago pendiente que esperabas en «%s».',
        ],
        ActivityEvent::PAYMENT_REJECTED => [
            'Rechazaron tu pago',
            'El receptor rechazó tu pago en «%s». Vas a tener que registrarlo de nuevo.',
        ],
    ];

    public function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {}

    public static function for(string $eventType, string $recipientName, string $projectName, string $link): self
    {
        [$title, $line] = self::TEXTS[$eventType] ?? ['Tenés una novedad', 'Hay una novedad en «%s».'];

        return new self(
            "Finanzas ONG: {$title}",
            sprintf("Hola %s,\n\n%s\n\nRevisalo en: %s\n\n— Finanzas ONG", $recipientName, sprintf($line, $projectName), $link),
        );
    }
}
