<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'members')]
class Member implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text', unique: true)]
    private string $email;

    #[ORM\Column(type: 'text')]
    private string $password;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'text', unique: true)]
    private string $username;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'telegram_chat_id', type: 'bigint', nullable: true)]
    private ?string $telegramChatId = null;

    // Only the SHA-256 of the one-time /start token is stored, never the token itself.
    #[ORM\Column(name: 'telegram_link_token_hash', type: 'text', nullable: true)]
    private ?string $telegramLinkTokenHash = null;

    #[ORM\Column(name: 'telegram_link_expires_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $telegramLinkExpiresAt = null;

    public function __construct(string $name, string $email)
    {
        $this->name     = $name;
        $this->email    = $email;
        $this->username = strtolower((string) strstr($email, '@', true));
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function getUsername(): string { return $this->username; }
    public function setUsername(string $username): void { $this->username = $username; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }

    public function getTelegramChatId(): ?string { return $this->telegramChatId; }
    public function isTelegramLinked(): bool { return $this->telegramChatId !== null; }

    public function startTelegramLink(string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $this->telegramLinkTokenHash = $tokenHash;
        $this->telegramLinkExpiresAt = $expiresAt;
    }

    public function completeTelegramLink(string $chatId): void
    {
        $this->telegramChatId        = $chatId;
        $this->telegramLinkTokenHash = null;
        $this->telegramLinkExpiresAt = null;
    }

    public function unlinkTelegram(): void
    {
        $this->telegramChatId        = null;
        $this->telegramLinkTokenHash = null;
        $this->telegramLinkExpiresAt = null;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_MEMBER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): void { $this->roles = $roles; }
    public function getUserIdentifier(): string { return $this->email; }
    public function eraseCredentials(): void {}
}
