<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Settings;

use Doctrine\DBAL\Connection;

final readonly class PlatformSettings
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->connection->fetchOne(
            'SELECT value FROM platform_settings WHERE setting_key = :key AND valid_from <= :now ORDER BY valid_from DESC, id DESC LIMIT 1',
            [
                'key' => $key,
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
        );

        if ($value === false) {
            return $default;
        }

        try {
            return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }
    }
}
