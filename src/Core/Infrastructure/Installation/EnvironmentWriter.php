<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Installation;

use Symfony\Component\Filesystem\Filesystem;

final readonly class EnvironmentWriter
{
    public function __construct(
        private string $projectDirectory,
        private Filesystem $filesystem,
    ) {
    }

    public function write(InstallationInput $input, bool $installerEnabled): void
    {
        $values = [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_SECRET' => bin2hex(random_bytes(32)),
            'APP_INSTALLER_ENABLED' => $installerEnabled ? '1' : '0',
            'DEFAULT_URI' => rtrim($input->baseDomain, '/'),
            'APP_TRUSTED_HOST' => '^'.preg_quote((string) parse_url($input->baseDomain, PHP_URL_HOST), '/').'$',
            'DATABASE_URL' => $input->database->databaseUrl(),
            'LOCK_DSN' => 'flock',
            'MAILER_DSN' => $input->mailerDsn,
            'MAIL_FROM' => $input->mailFrom,
        ];

        $contents = "# Automatisch durch den Web-Installer erzeugt. Nicht versionieren.\n";
        foreach ($values as $key => $value) {
            $contents .= $key . '=' . $this->quote($value) . "\n";
        }

        $target = $this->projectDirectory . '/.env.local';
        $this->filesystem->dumpFile($target, $contents);
        @chmod($target, 0600);
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
