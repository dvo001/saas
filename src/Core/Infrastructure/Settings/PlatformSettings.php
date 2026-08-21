<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Settings;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Entity\PlatformSetting;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlatformSettings
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private AuditLogger $audit,
    ) {}

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

    /** @param array<mixed>|bool|float|int|string|null $value */
    public function set(string $key, array|bool|float|int|string|null $value, PlatformAdmin $actor, string $ip): void
    {
        if (!in_array($key, ['platform.name', 'platform.operator', 'mail.system_sender', 'cron.intervals'], true)) {
            throw new \DomainException('Diese Plattform-Einstellung darf hier nicht geändert werden.');
        }

        $previous = $this->get($key);
        $validFrom = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $latest = $this->connection->fetchOne('SELECT MAX(valid_from) FROM platform_settings WHERE setting_key = :key', ['key' => $key]);
        if (is_string($latest) && $latest !== '') {
            $latestAt = new \DateTimeImmutable($latest, new \DateTimeZone('UTC'));
            if ($latestAt >= $validFrom) {
                $validFrom = $latestAt->add(new \DateInterval('PT1S'));
            }
        }

        $this->entityManager->persist(new PlatformSetting($key, $value, $validFrom, $actor));
        $this->entityManager->flush();
        $this->audit->logPlatform('platform_setting.changed', 'platform_setting', null, $actor, [
            'key' => $key,
            'old_value' => $previous,
            'new_value' => $value,
            'valid_from' => $validFrom->format(DATE_ATOM),
        ], null, $ip);
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 30): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT ps.setting_key, ps.value, ps.valid_from, ps.created_at, pa.display_name, pa.email
            FROM platform_settings ps
            LEFT JOIN platform_admins pa ON pa.id = ps.changed_by_platform_admin_id
            ORDER BY ps.created_at DESC, ps.id DESC
            LIMIT :limit
            SQL, ['limit' => max(1, min($limit, 100))], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);
    }
}
