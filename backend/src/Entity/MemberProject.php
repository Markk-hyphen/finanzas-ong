<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'member_projects')]
class MemberProject
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'member_id', nullable: false)]
    private Member $member;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: false)]
    private Project $project;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $percentage;

    public function __construct(Member $member, Project $project, string $percentage)
    {
        $this->member     = $member;
        $this->project    = $project;
        $this->percentage = $percentage;
    }

    public function getMember(): Member { return $this->member; }
    public function getProject(): Project { return $this->project; }
    public function getPercentage(): string { return $this->percentage; }
    public function setPercentage(string $percentage): void { $this->percentage = $percentage; }
}
