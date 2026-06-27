<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Member;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

final class MemberFactory extends PersistentProxyObjectFactory
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    public static function class(): string
    {
        return Member::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'name'     => self::faker()->name(),
            'email'    => self::faker()->unique()->safeEmail(),
            'password' => 'password',
            'roles'    => [],
            'active'   => true,
        ];
    }

    protected function initialize(): static
    {
        return $this->afterInstantiate(function (Member $member): void {
            $member->setPassword($this->hasher->hashPassword($member, $member->getPassword()));
        });
    }
}
