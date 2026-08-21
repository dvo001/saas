<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine\Entity;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Domain\Tenant\TenantOwnedEntity;
use App\Core\Infrastructure\Doctrine\Repository\TenantUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: TenantUserRepository::class)]
#[ORM\Table(name: 'tenant_users')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_user_email', columns: ['tenant_id', 'email'])]
class TenantUser implements UserInterface, PasswordAuthenticatedUserInterface, TenantOwnedEntity
{
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(length: 36, unique: true)]
        private string $publicId,
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 120)]
        private string $displayName,
        #[ORM\Column(length: 32, enumType: TenantRole::class)]
        private TenantRole $tenantRole,
        #[ORM\Column]
        private string $password,
        #[ORM\Column(options: ['default' => true])]
        private bool $active = true,
        #[ORM\Column(options: ['default' => false])]
        private bool $emailConfirmed = false,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->email = mb_strtolower(trim($email));
        $this->displayName = trim($displayName);
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

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getPublicId(): string { return $this->publicId; }
    public function getDisplayName(): string { return $this->displayName; }
    public function getTenantRole(): TenantRole { return $this->tenantRole; }
    public function getUserIdentifier(): string
    {
        if ($this->email === '') {
            throw new \LogicException('A tenant user must have an email address.');
        }

        return $this->tenant->getPublicId().'|'.$this->email;
    }
    public function getEmail(): string { return $this->email; }
    public function getPassword(): string { return $this->password; }
    public function getAuthVersion(): int { return $this->authVersion; }
    public function getTotpSecretEncrypted(): ?string { return $this->totpSecretEncrypted; }
    public function isEmailConfirmed(): bool { return $this->emailConfirmed; }
    public function isActive(): bool { return $this->active; }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_TENANT_USER', $this->tenantRole->securityRole()];
    }

    public function eraseCredentials(): void {}

    public function confirmEmail(): void { $this->emailConfirmed = true; }
    public function requiresTwoFactor(): bool { return $this->tenantRole->requiresTwoFactor(); }
    public function hasTwoFactor(): bool { return $this->totpSecretEncrypted !== null; }
    public function enableTwoFactor(string $encryptedSecret): void { $this->totpSecretEncrypted = $encryptedSecret; ++$this->authVersion; }
    public function disableTwoFactor(): void { $this->totpSecretEncrypted = null; ++$this->authVersion; }
    public function changePassword(string $password): void { $this->password = $password; ++$this->authVersion; }
    public function deactivate(): void { $this->active = false; ++$this->authVersion; }
    public function reactivate(): void { $this->active = true; ++$this->authVersion; }
    public function rename(string $displayName): void { $this->displayName = trim($displayName); }
    public function changeRole(TenantRole $role): void { $this->tenantRole = $role; ++$this->authVersion; }

    public function anonymize(string $password, \DateTimeImmutable $now): void
    {
        $this->email = 'deleted+'.str_replace('-', '', $this->publicId).'@invalid.local';
        $this->displayName = 'Gelöschter Benutzer';
        $this->password = $password;
        $this->active = false;
        $this->totpSecretEncrypted = null;
        $this->deletedAt = $now;
        ++$this->authVersion;
    }

    public function isLocked(\DateTimeImmutable $now): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > $now;
    }

    public function registerFailedLogin(\DateTimeImmutable $now): void
    {
        ++$this->failedLoginCount;
        if ($this->failedLoginCount >= 5) {
            $this->lockedUntil = $now->add(new \DateInterval('PT15M'));
            ++$this->authVersion;
        }
    }

    public function registerSuccessfulLogin(): void
    {
        $this->failedLoginCount = 0;
        $this->lockedUntil = null;
    }

    public function unlock(): void
    {
        $this->failedLoginCount = 0;
        $this->lockedUntil = null;
        ++$this->authVersion;
    }
}
