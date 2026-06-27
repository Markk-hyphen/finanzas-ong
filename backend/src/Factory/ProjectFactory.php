<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Project;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

final class ProjectFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Project::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'name' => self::faker()->words(3, true),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
