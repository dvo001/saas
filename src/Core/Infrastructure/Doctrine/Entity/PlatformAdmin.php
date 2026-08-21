<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Core\Infrastructure\Doctrine\Repository\PlatformAdminRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: PlatformAdminRepository::class)]
#[ORM\Table(name: 'platform_admins')]
#[ORM\UniqueConstraint(name: 'uniq_platform_admin_email', columns: ['email'])]
class PlatformAdmin implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** @param list<string> $roles */
    public function __construct(
        #[ORM\Column(length: 36, unique: true)]
        private string $publicId,
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column]
        private string $password,
        #[ORM\Column(length: 120)]
        private string $displayName = '',
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_PLATFORM_ADMIN'],
        #[ORM\Column(options: ['default' => true])]
        private bool $active = true,
        #[ORM\Column(options: ['default' => true])]
        private bool $emailConfirmed = true,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->email = mb_strtolower(trim($email));
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $totpSecretEncrypted = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $failedLoginCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $authVersion = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getEmail(): string { return $this->email; }
    public function getDisplayName(): string { return $this->displayName !== '' ? $this->displayName : $this->email; }

    public function getUserIdentifier(): string
    {
        if ($this->email === '') {
            throw new \LogicException('A platform admin must have an email address.');
        }

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_PLATFORM_ADMIN']));
    }

    public function eraseCredentials(): void
    {
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isEmailConfirmed(): bool { return $this->emailConfirmed; }
    public function hasTwoFactor(): bool { return $this->totpSecretEncrypted !== null; }
    public function getTotpSecretEncrypted(): ?string { return $this->totpSecretEncrypted; }
    public function getAuthVersion(): int { return $this->authVersion; }

    public function enableTwoFactor(string $secret): void { $this->totpSecretEncrypted = $secret; ++$this->authVersion; }
    public function confirmInvitation(string $displayName, string $password): void { $this->displayName = trim($displayName); $this->password = $password; $this->emailConfirmed = true; ++$this->authVersion; }
    public function changePassword(string $password): void { $this->password = $password; ++$this->authVersion; }
    public function deactivate(): void { $this->active = false; ++$this->authVersion; }
    public function reactivate(): void { $this->active = true; ++$this->authVersion; }
    public function unlock(): void { $this->failedLoginCount = 0; $this->lockedUntil = null; ++$this->authVersion; }

    public function isLocked(\DateTimeImmutable $now): bool { return $this->lockedUntil !== null && $this->lockedUntil > $now; }

    public function registerFailedLogin(\DateTimeImmutable $now): void
    {
        ++$this->failedLoginCount;
        if ($this->failedLoginCount >= 5) {
            $this->lockedUntil = $now->add(new \DateInterval('PT15M'));
            ++$this->authVersion;
        }
    }

    public function registerSuccessfulLogin(): void { $this->failedLoginCount = 0; $this->lockedUntil = null; }

    public function anonymize(string $password, \DateTimeImmutable $now): void
    {
        $this->email = 'deleted+'.str_replace('-', '', $this->publicId).'@invalid.local';
        $this->displayName = 'Gelöschter Plattformadmin';
        $this->password = $password;
        $this->roles = [];
        $this->active = false;
        $this->totpSecretEncrypted = null;
        $this->deletedAt = $now;
        ++$this->authVersion;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
