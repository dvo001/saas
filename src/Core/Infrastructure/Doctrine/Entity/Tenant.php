<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine\Entity;

use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Repository\TenantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenants')]
class Tenant
{
    public function __construct(
        #[ORM\Column(length: 36, unique: true)]
        private string $publicId,
        #[ORM\Column(length: 180, unique: true)]
        private string $name,
        #[ORM\Column(length: 80, unique: true)]
        private string $slug,
        #[ORM\Column(length: 32, enumType: TenantStatus::class)]
        private TenantStatus $status,
        #[ORM\Column(length: 32, enumType: TrialModule::class)]
        private TrialModule $trialModule,
        #[ORM\Column(length: 20)]
        private string $legalVersion,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $legalAcceptedAt,
        #[ORM\Column(options: ['default' => true])]
        private bool $supportImpersonationEnabled = true,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->name = trim($name);
        $this->slug = mb_strtolower(trim($slug));
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trialStartsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registrationReminderSentAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoStoragePath = null;

    public function getId(): ?int { return $this->id; }
    public function getPublicId(): string { return $this->publicId; }
    public function getName(): string { return $this->name; }
    public function getSlug(): string { return $this->slug; }
    public function getStatus(): TenantStatus { return $this->status; }
    public function getTrialModule(): TrialModule { return $this->trialModule; }
    public function isSupportImpersonationEnabled(): bool { return $this->supportImpersonationEnabled; }
    public function setSupportImpersonationEnabled(bool $enabled): void { $this->supportImpersonationEnabled = $enabled; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLogoStoragePath(): ?string { return $this->logoStoragePath; }

    public function confirm(\DateTimeImmutable $now): void
    {
        if ($this->status !== TenantStatus::PendingConfirmation) {
            throw new \DomainException('Only an unconfirmed tenant can be confirmed.');
        }

        $this->status = TenantStatus::Trial;
        $this->confirmedAt = $now;
        $this->trialStartsAt = $now;
        $this->trialEndsAt = $now->add(new \DateInterval('P14D'));
    }

    public function changePendingSlug(string $slug): void
    {
        if ($this->status !== TenantStatus::PendingConfirmation) {
            throw new \DomainException('The tenant slug is immutable after activation.');
        }

        $this->slug = mb_strtolower(trim($slug));
    }
}
