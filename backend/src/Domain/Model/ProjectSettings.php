<?php

declare(strict_types=1);

namespace App\Domain\Model;

final class ProjectSettings
{
    public const MODE_REQUIRED     = 'required';
    public const MODE_OPTIMISTIC   = 'optimistic';
    public const MODE_AUTO_TIMEOUT = 'auto_timeout';

    public const MODES = [self::MODE_REQUIRED, self::MODE_OPTIMISTIC, self::MODE_AUTO_TIMEOUT];

    public function __construct(
        public readonly string $confirmationMode,
        // optimistic: how long the receiver can reject; auto_timeout: how long until it self-confirms
        public readonly int    $objectionWindowHours,
        // window for the author to undo their own movement; 0 disables it
        public readonly int    $selfCancelMinutes,
    ) {}
}
