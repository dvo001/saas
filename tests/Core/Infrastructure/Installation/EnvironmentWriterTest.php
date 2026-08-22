<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Installation;

use App\Core\Infrastructure\Installation\DatabaseConfiguration;
use App\Core\Infrastructure\Installation\EnvironmentWriter;
use App\Core\Infrastructure\Installation\InstallationInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

final class EnvironmentWriterTest extends TestCase
{
    public function testItWritesProductionSafeHostAndSenderConfiguration(): void
    {
        $directory = sys_get_temp_dir().'/saas-env-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);

        try {
            $input = new InstallationInput(
                new DatabaseConfiguration('localhost', 3306, 'saas', 'user', 'secret'),
                'Vereinssport Schweiz',
                'https://club.example.ch',
                'admin@example.ch',
                'a-long-password',
                "smtp://user:pa'ss@mail.example.ch",
                'noreply@example.ch',
            );
            (new EnvironmentWriter($directory, $filesystem))->write($input, false);

            $contents = file_get_contents($directory.'/.env.local');
            self::assertIsString($contents);
            $values = (new Dotenv())->parse($contents, '.env.local');
            self::assertSame('prod', $values['APP_ENV']);
            self::assertSame('0', $values['APP_INSTALLER_ENABLED']);
            self::assertSame('^club\\.example\\.ch$', $values['APP_TRUSTED_HOST']);
            self::assertSame('noreply@example.ch', $values['MAIL_FROM']);
            self::assertSame("smtp://user:pa'ss@mail.example.ch", $values['MAILER_DSN']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $values['APP_SECRET']);
        } finally {
            $filesystem->remove($directory);
        }
    }
}
