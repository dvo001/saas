<?php

declare(strict_types=1);

namespace App\Core\Domain\Tenant;

enum TenantRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case EventManager = 'event_manager';
    case DataEntry = 'data_entry';
    case ReadOnly = 'read_only';

    public function securityRole(): string
    {
        return 'ROLE_TENANT_'.strtoupper($this->value);
    }

    public function requiresTwoFactor(): bool
    {
        return $this === self::Owner || $this === self::Administrator;
    }

    public function sensitiveSession(): bool
    {
        return $this === self::Owner || $this === self::Administrator;
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Administrator => 'Administrator',
            self::EventManager => 'Event-Manager',
            self::DataEntry => 'Datenerfassung',
            self::ReadOnly => 'Nur Lesen',
        };
    }
}
