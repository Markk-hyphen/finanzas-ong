<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Model\MovementRecord;
use App\Domain\Model\ProjectSettings;
use App\Domain\Service\CancellationPolicy;
use PHPUnit\Framework\TestCase;

class CancellationPolicyTest extends TestCase
{
    private const PAYER    = 1;
    private const RECEIVER = 2;
    private const OTHER    = 3;

    private CancellationPolicy $policy;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->policy = new CancellationPolicy();
        $this->now    = new \DateTimeImmutable('2026-10-21 12:00:00');
    }

    // --- expenses: self-correction inside the window

    public function testAuthorCanUndoOwnExpenseInsideWindow(): void
    {
        $expense = $this->expense(self::PAYER, '-10 minutes');

        $this->assertSame('self_correction', $this->kind('undo', $expense, self::PAYER));
    }

    public function testAuthorCannotUndoExpenseAfterWindow(): void
    {
        $expense = $this->expense(self::PAYER, '-16 minutes');

        $this->assertNull($this->kind('undo', $expense, self::PAYER));
    }

    public function testOtherMemberCannotUndoSomeoneElsesExpense(): void
    {
        $expense = $this->expense(self::PAYER, '-1 minute');

        $this->assertNull($this->kind('undo', $expense, self::OTHER));
    }

    public function testZeroWindowDisablesSelfCorrection(): void
    {
        $expense  = $this->expense(self::PAYER, '-1 second');
        $settings = new ProjectSettings('required', 48, 0);

        $this->assertNull($this->policy->kindFor('undo', $expense, self::PAYER, $settings, $this->now));
    }

    // --- payer: withdraw

    public function testPayerCanWithdrawPendingPaymentAtAnyTime(): void
    {
        $payment = $this->payment('pending', null, '-30 days');

        $this->assertSame('withdrawal', $this->kind('withdraw', $payment, self::PAYER));
    }

    public function testPayerCannotWithdrawPaymentConfirmedByReceiver(): void
    {
        $payment = $this->payment('confirmed', 'receiver', '-1 minute');

        $this->assertNull($this->kind('withdraw', $payment, self::PAYER));
    }

    public function testPayerCanCorrectOptimisticPaymentInsideWindow(): void
    {
        $payment = $this->payment('confirmed', 'optimistic', '-5 minutes');

        $this->assertSame('self_correction', $this->kind('withdraw', $payment, self::PAYER));
    }

    public function testPayerCannotCorrectOptimisticPaymentAfterWindow(): void
    {
        $payment = $this->payment('confirmed', 'optimistic', '-20 minutes');

        $this->assertNull($this->kind('withdraw', $payment, self::PAYER));
    }

    public function testReceiverCannotWithdraw(): void
    {
        $payment = $this->payment('pending', null, '-1 minute');

        $this->assertNull($this->kind('withdraw', $payment, self::RECEIVER));
    }

    // --- receiver: reject

    public function testReceiverCanRejectPendingPayment(): void
    {
        $payment = $this->payment('pending', null, '-30 days');

        $this->assertSame('rejection', $this->kind('reject', $payment, self::RECEIVER));
    }

    public function testReceiverCanRejectOptimisticPaymentInsideObjectionWindow(): void
    {
        $payment = $this->payment('confirmed', 'optimistic', '-47 hours');

        $this->assertSame('rejection', $this->kind('reject', $payment, self::RECEIVER));
    }

    public function testReceiverCannotRejectOptimisticPaymentAfterObjectionWindow(): void
    {
        $payment = $this->payment('confirmed', 'optimistic', '-49 hours');

        $this->assertNull($this->kind('reject', $payment, self::RECEIVER));
    }

    public function testReceiverCannotRejectWhatTheyConfirmed(): void
    {
        $payment = $this->payment('confirmed', 'receiver', '-1 minute');

        $this->assertNull($this->kind('reject', $payment, self::RECEIVER));
    }

    public function testReceiverCannotRejectAutoConfirmedPayment(): void
    {
        // Silence until the deadline was the receiver's answer.
        $payment = $this->payment('confirmed', 'timeout', '-1 hour');

        $this->assertNull($this->kind('reject', $payment, self::RECEIVER));
    }

    public function testPayerCannotRejectOwnPayment(): void
    {
        $payment = $this->payment('pending', null, '-1 minute');

        $this->assertNull($this->kind('reject', $payment, self::PAYER));
    }

    // --- actions list

    public function testActionsForPendingPaymentDependOnActor(): void
    {
        $payment  = $this->payment('pending', null, '-1 minute');
        $settings = $this->settings();

        $this->assertSame(['withdraw'], $this->policy->actionsFor($payment, self::PAYER, $settings, $this->now));
        $this->assertSame(['reject'], $this->policy->actionsFor($payment, self::RECEIVER, $settings, $this->now));
        $this->assertSame([], $this->policy->actionsFor($payment, self::OTHER, $settings, $this->now));
    }

    public function testCancellationsHaveNoActions(): void
    {
        $cancellation = new MovementRecord(9, 'cancellation', 'confirmed', null, null, null, null, 1,
            createdAt: $this->now, createdById: self::PAYER);

        $this->assertSame([], $this->policy->actionsFor($cancellation, self::PAYER, $this->settings(), $this->now));
    }

    // --- helpers

    private function kind(string $action, MovementRecord $m, int $actorId): ?string
    {
        return $this->policy->kindFor($action, $m, $actorId, $this->settings(), $this->now);
    }

    private function settings(): ProjectSettings
    {
        return new ProjectSettings('required', 48, 15);
    }

    private function expense(int $authorId, string $age): MovementRecord
    {
        return new MovementRecord(1, 'expense', 'confirmed', $authorId, null, '100.00', null, null,
            createdAt: $this->now->modify($age), createdById: $authorId);
    }

    private function payment(string $status, ?string $confirmedVia, string $age): MovementRecord
    {
        return new MovementRecord(2, 'payment', $status, self::PAYER, self::RECEIVER, '50.00', 'batch', null,
            createdAt: $this->now->modify($age), createdById: self::PAYER, confirmedVia: $confirmedVia);
    }
}
