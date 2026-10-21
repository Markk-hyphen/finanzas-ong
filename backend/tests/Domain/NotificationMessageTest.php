<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Model\ActivityEvent;
use App\Domain\Service\NotificationMessage;
use PHPUnit\Framework\TestCase;

class NotificationMessageTest extends TestCase
{
    public function testIncludesProjectNameAndLink(): void
    {
        $msg = NotificationMessage::for(ActivityEvent::PAYMENT_REJECTED, 'Ana', 'Techo 2026', 'https://app/#/project/7');

        $this->assertSame('Finanzas ONG: Rechazaron tu pago', $msg->subject);
        $this->assertStringContainsString('Hola Ana,', $msg->body);
        $this->assertStringContainsString('«Techo 2026»', $msg->body);
        $this->assertStringContainsString('https://app/#/project/7', $msg->body);
    }

    public function testEveryNotifiedEventHasItsOwnText(): void
    {
        $events = [
            ActivityEvent::PAYMENT_CREATED_OPTIMISTIC,
            ActivityEvent::PAYMENT_CREATED_AUTO_TIMEOUT,
            ActivityEvent::PAYMENT_AUTO_CONFIRMED,
            ActivityEvent::PAYMENT_WITHDRAWN,
            ActivityEvent::PAYMENT_REJECTED,
        ];

        foreach ($events as $event) {
            $this->assertNotSame('Finanzas ONG: Tenés una novedad', NotificationMessage::for($event, 'A', 'P', 'L')->subject, $event);
        }
    }

    public function testNeverCarriesAmounts(): void
    {
        // The message is built only from event type, name, project and link: no amount can leak.
        $msg = NotificationMessage::for(ActivityEvent::PAYMENT_CREATED_OPTIMISTIC, 'Ana', 'P', 'L');

        $this->assertDoesNotMatchRegularExpression('/\$\s?\d|\d+[.,]\d{2}/', $msg->body);
    }
}
