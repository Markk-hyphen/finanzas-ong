<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Every event type ActivityRecorder writes to the audit log (MANIFIESTO
 * amendment E2). Centralized so AuditLog rows and NotificationTargets agree
 * on the same vocabulary — adding a notification for an existing event never
 * requires renaming anything.
 */
final class ActivityEvent
{
    public const EXPENSE_CREATED  = 'expense_created';
    public const EXPENSE_UNDONE   = 'expense_undone';
    public const PAYMENT_CREATED  = 'payment_created';
    // Optimistic payments are born confirmed; this is the one that needs a nudge.
    public const PAYMENT_CREATED_OPTIMISTIC = 'payment_created_optimistic';
    // Pending, but silence until the deadline means acceptance.
    public const PAYMENT_CREATED_AUTO_TIMEOUT = 'payment_created_auto_timeout';
    public const PAYMENT_CONFIRMED          = 'payment_confirmed';
    public const PAYMENT_AUTO_CONFIRMED     = 'payment_auto_confirmed';
    public const PAYMENT_WITHDRAWN          = 'payment_withdrawn';
    public const PAYMENT_REJECTED           = 'payment_rejected';
    public const PAYMENT_SELF_CORRECTED     = 'payment_self_corrected';
    public const MOVEMENT_CANCELLED_ADMIN   = 'movement_cancelled_admin';

    private function __construct() {}
}
