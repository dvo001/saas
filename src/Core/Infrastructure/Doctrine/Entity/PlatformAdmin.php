<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
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
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_PLATFORM_ADMIN'],
        #[ORM\Column(options: ['default' => true])]
        private bool $active = true,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->email = mb_strtolower(trim($email));
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
