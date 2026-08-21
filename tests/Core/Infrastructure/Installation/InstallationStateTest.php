<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Installation;

use App\Core\Infrastructure\Installation\InstallationState;
use PHPUnit\Framework\TestCase;

final class InstallationStateTest extends TestCase
{
    public function testInstallerIsAvailableOnlyWhenEnabledAndUnlocked(): void
    {
        $path = sys_get_temp_dir() . '/saas-install-' . bin2hex(random_bytes(8));
        $state = new InstallationState($path, true);

        self::assertTrue($state->isInstallerAvailable());
        file_put_contents($path, 'installed');

        try {
            self::assertTrue($state->isInstalled());
            self::assertFalse($state->isInstallerAvailable());
        } finally {
            unlink($path);
        }
    }

    public function testDisabledInstallerIsUnavailable(): void
    {
        $state = new InstallationState('/path/that/does/not/exist', false);

        self::assertFalse($state->isInstallerAvailable());
    }
}
