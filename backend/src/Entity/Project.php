<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Model\ProjectSettings;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'projects')]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    // Settings of MANIFIESTO amendment E1 (see docs/fase1-modos-de-confirmacion.md)
    #[ORM\Column(name: 'confirmation_mode', type: 'text', options: ['default' => ProjectSettings::MODE_REQUIRED])]
    private string $confirmationMode = ProjectSettings::MODE_REQUIRED;

    #[ORM\Column(name: 'objection_window_hours', type: 'integer', options: ['default' => 48])]
    private int $objectionWindowHours = 48;

    #[ORM\Column(name: 'self_cancel_minutes', type: 'integer', options: ['default' => 15])]
    private int $selfCancelMinutes = 15;

    public function __construct(string $name)
    {
        $this->name      = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getSettings(): ProjectSettings
    {
        return new ProjectSettings($this->confirmationMode, $this->objectionWindowHours, $this->selfCancelMinutes);
    }

    public function applySettings(ProjectSettings $settings): void
    {
        $this->confirmationMode     = $settings->confirmationMode;
        $this->objectionWindowHours = $settings->objectionWindowHours;
        $this->selfCancelMinutes    = $settings->selfCancelMinutes;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
