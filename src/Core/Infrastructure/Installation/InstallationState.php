<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Installation;

final readonly class InstallationState
{
    public function __construct(
        private string $lockPath,
        private bool $installerEnabled,
    ) {
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath);
    }

    public function isInstallerAvailable(): bool
    {
        return $this->installerEnabled && !$this->isInstalled();
    }

    public function lockPath(): string
    {
        return $this->lockPath;
    }
}
