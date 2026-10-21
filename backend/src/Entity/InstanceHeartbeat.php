<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

// Last time a background process of this instance reported alive. The
// scheduler beats on every run; its age tells whether the instance can offer
// features that depend on it (auto_timeout).
#[ORM\Entity]
#[ORM\Table(name: 'instance_heartbeats')]
class InstanceHeartbeat
{
    public const SCHEDULER = 'scheduler';

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(name: 'beat_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $beatAt;

    public function __construct(string $name, \DateTimeImmutable $beatAt)
    {
        $this->name   = $name;
        $this->beatAt = $beatAt;
    }

    public function getName(): string { return $this->name; }
    public function getBeatAt(): \DateTimeImmutable { return $this->beatAt; }
    public function beat(\DateTimeImmutable $at): void { $this->beatAt = $at; }
}
