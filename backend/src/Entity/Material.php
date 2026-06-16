<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'materials')]
class Material
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(name: 'normalized_name', type: 'text')]
    private string $normalizedName;

    public function __construct(string $name)
    {
        $this->name           = $name;
        $this->normalizedName = self::normalize($name);
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }

    public function setName(string $name): void
    {
        $this->name           = $name;
        $this->normalizedName = self::normalize($name);
    }

    public function getNormalizedName(): string { return $this->normalizedName; }

    public static function normalize(string $name): string
    {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        return strtolower(trim($str));
    }
}
