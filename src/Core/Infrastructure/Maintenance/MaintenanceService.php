<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Maintenance;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class MaintenanceService
{
    public function __construct(private Connection $connection, private AuditLogger $audit) {}

    /** @return array<string, mixed>|null */
    public function active(?\DateTimeImmutable $at = null): ?array
    {
        $at ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT public_id, starts_at, expected_end_at, message
            FROM maintenance_windows
            WHERE cancelled_at IS NULL AND starts_at <= :now AND expected_end_at > :now
            ORDER BY starts_at DESC LIMIT 1
            SQL, ['now' => $at->format('Y-m-d H:i:s')]);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function next(): ?array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT public_id, starts_at, expected_end_at, message
            FROM maintenance_windows
            WHERE cancelled_at IS NULL AND starts_at > :now
            ORDER BY starts_at ASC LIMIT 1
            SQL, ['now' => gmdate('Y-m-d H:i:s')]);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT mw.public_id, mw.starts_at, mw.expected_end_at, mw.message, mw.cancelled_at, mw.created_at,
                   pa.display_name, pa.email
            FROM maintenance_windows mw
            JOIN platform_admins pa ON pa.id = mw.created_by_platform_admin_id
            ORDER BY mw.created_at DESC LIMIT 50
            SQL);
    }

    public function schedule(PlatformAdmin $admin, \DateTimeImmutable $startsAt, int $durationMinutes, string $message, string $ip): string
    {
        $message = trim($message);
        if ($durationMinutes < 5 || $durationMinutes > 1440) {
            throw new \DomainException('Die erwartete Dauer muss zwischen 5 Minuten und 24 Stunden liegen.');
        }
        if ($message === '') {
            throw new \DomainException('Bitte einen Wartungstext angeben.');
        }
        if ($startsAt < new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC'))) {
            throw new \DomainException('Der Wartungsstart darf nicht in der Vergangenheit liegen.');
        }

        $publicId = Uuid::v7()->toRfc4122();
        $expectedEndAt = $startsAt->add(new \DateInterval('PT'.$durationMinutes.'M'));
        $this->connection->insert('maintenance_windows', [
            'public_id' => $publicId,
            'created_by_platform_admin_id' => $admin->getId() ?? throw new \LogicException('Missing platform admin id.'),
            'starts_at' => $startsAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'expected_end_at' => $expectedEndAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'message' => mb_substr($message, 0, 1000),
            'cancelled_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit->logPlatform('maintenance.scheduled', 'maintenance_window', $publicId, $admin, [
            'starts_at' => $startsAt->format(DATE_ATOM),
            'expected_end_at' => $expectedEndAt->format(DATE_ATOM),
            'message' => $message,
        ], null, $ip);

        return $publicId;
    }

    public function cancel(PlatformAdmin $admin, string $publicId, string $ip): void
    {
        $affected = $this->connection->executeStatement(<<<'SQL'
            UPDATE maintenance_windows SET cancelled_at = :now
            WHERE public_id = :public_id AND cancelled_at IS NULL AND expected_end_at > :now
            SQL, ['now' => gmdate('Y-m-d H:i:s'), 'public_id' => $publicId]);
        if ($affected === 0) {
            throw new \DomainException('Dieses Wartungsfenster ist bereits beendet oder abgesagt.');
        }
        $this->audit->logPlatform('maintenance.cancelled', 'maintenance_window', $publicId, $admin, [], null, $ip);
    }
}
