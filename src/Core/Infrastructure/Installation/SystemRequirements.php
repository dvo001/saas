<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Installation;

final readonly class SystemRequirements
{
    /** @return list<array{name: string, met: bool, detail: string}> */
    public function check(string $projectDirectory): array
    {
        $checks = [
            ['name' => 'PHP 8.2 oder neuer', 'met' => version_compare(PHP_VERSION, '8.2.0', '>='), 'detail' => PHP_VERSION],
        ];

        foreach (['ctype', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'sodium'] as $extension) {
            $checks[] = [
                'name' => 'PHP-Erweiterung ' . $extension,
                'met' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? 'verfügbar' : 'fehlt',
            ];
        }

        foreach (['storage', 'storage/uploads', 'storage/exports', 'var'] as $directory) {
            $path = $projectDirectory . '/' . $directory;
            $checks[] = [
                'name' => 'Schreibrecht ' . $directory,
                'met' => is_dir($path) && is_writable($path),
                'detail' => is_dir($path) && is_writable($path) ? 'beschreibbar' : 'nicht beschreibbar',
            ];
        }

        $checks[] = [
            'name' => 'Konfigurationsverzeichnis',
            'met' => is_writable($projectDirectory),
            'detail' => is_writable($projectDirectory) ? '.env.local kann erstellt werden' : 'Projektverzeichnis nicht beschreibbar',
        ];

        return $checks;
    }

    /** @param list<array{name: string, met: bool, detail: string}> $checks */
    public function allMet(array $checks): bool
    {
        return array_reduce($checks, static fn (bool $met, array $check): bool => $met && $check['met'], true);
    }
}
