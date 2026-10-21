<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Member;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

// Runs on login and on every JWT-authenticated request, so deactivating a
// member also invalidates the tokens they already hold.
final class MemberUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof Member && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Cuenta desactivada.');
        }
    }

    public function checkPostAuth(UserInterface $user): void {}
}
