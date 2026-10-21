<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Service\DeliveryBackoff;
use PHPUnit\Framework\TestCase;

class DeliveryBackoffTest extends TestCase
{
    public function testWaitsDoubleEachRetry(): void
    {
        $backoff = new DeliveryBackoff();
        $now     = new \DateTimeImmutable('2026-10-21 12:00:00');

        $this->assertEquals($now->modify('+1 minute'), $backoff->nextAttemptAt(1, $now));
        $this->assertEquals($now->modify('+2 minutes'), $backoff->nextAttemptAt(2, $now));
        $this->assertEquals($now->modify('+4 minutes'), $backoff->nextAttemptAt(3, $now));
        $this->assertEquals($now->modify('+8 minutes'), $backoff->nextAttemptAt(4, $now));
    }

    public function testGivesUpAfterFiveAttempts(): void
    {
        $this->assertNull((new DeliveryBackoff())->nextAttemptAt(5, new \DateTimeImmutable()));
    }
}
