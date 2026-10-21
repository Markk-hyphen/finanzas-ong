<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Model\ActivityEvent;
use App\Domain\Service\NotificationTargets;
use PHPUnit\Framework\TestCase;

class NotificationTargetsTest extends TestCase
{
    private const PAYER    = 1;
    private const RECEIVER = 2;

    private NotificationTargets $targets;

    protected function setUp(): void
    {
        $this->targets = new NotificationTargets();
    }

    public function testOptimisticPaymentNotifiesOnlyTheReceiver(): void
    {
        $ids = $this->targets->for(ActivityEvent::PAYMENT_CREATED_OPTIMISTIC, self::PAYER, self::RECEIVER);

        $this->assertSame([self::RECEIVER], $ids);
    }

    public function testAutoConfirmationNotifiesBothSides(): void
    {
        $ids = $this->targets->for(ActivityEvent::PAYMENT_AUTO_CONFIRMED, self::PAYER, self::RECEIVER);

        $this->assertSame([self::PAYER, self::RECEIVER], $ids);
    }

    public function testWithdrawalNotifiesOnlyTheReceiver(): void
    {
        $ids = $this->targets->for(ActivityEvent::PAYMENT_WITHDRAWN, self::PAYER, self::RECEIVER);

        $this->assertSame([self::RECEIVER], $ids);
    }

    public function testRejectionNotifiesOnlyThePayer(): void
    {
        $ids = $this->targets->for(ActivityEvent::PAYMENT_REJECTED, self::PAYER, self::RECEIVER);

        $this->assertSame([self::PAYER], $ids);
    }

    public function testUnknownEventNotifiesNobody(): void
    {
        $this->assertSame([], $this->targets->for('something_else', self::PAYER, self::RECEIVER));
    }

    public function testMissingMemberIsFilteredOut(): void
    {
        // e.g. an expense has no toMember
        $ids = $this->targets->for(ActivityEvent::PAYMENT_CREATED_OPTIMISTIC, self::PAYER, null);

        $this->assertSame([], $ids);
    }
}
