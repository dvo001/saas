<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Support;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;

final readonly class ActiveSupportSession
{
    public function __construct(
        public string $publicId,
        public PlatformAdmin $platformAdmin,
        public string $reason,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
