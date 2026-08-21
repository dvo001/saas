<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'platform_settings')]
#[ORM\UniqueConstraint(name: 'uniq_setting_effective_date', columns: ['setting_key', 'valid_from'])]
#[ORM\Index(name: 'idx_setting_effective', columns: ['setting_key', 'valid_from'])]
class PlatformSetting
{
    /** @param array<mixed>|bool|float|int|string|null $value */
    public function __construct(
        #[ORM\Column(name: 'setting_key', length: 100)]
        private string $key,
        #[ORM\Column(type: 'json')]
        private array|bool|float|int|string|null $value,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $validFrom,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'changed_by_platform_admin_id', onDelete: 'SET NULL')]
        private ?PlatformAdmin $changedBy = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return array<mixed>|bool|float|int|string|null */
    public function getValue(): array|bool|float|int|string|null
    {
        return $this->value;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChangedBy(): ?PlatformAdmin
    {
        return $this->changedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
