<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class MemberUserProvider implements UserProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $field  = str_contains($identifier, '@') ? 'email' : 'username';
        $member = $this->em->getRepository(Member::class)->findOneBy([$field => $identifier]);

        if (!$member) {
            throw new UserNotFoundException(sprintf('Member "%s" not found.', $identifier));
        }

        return $member;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof Member) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === Member::class || is_subclass_of($class, Member::class);
    }
}
