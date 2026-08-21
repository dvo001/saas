<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Installation;

use App\Core\Infrastructure\Installation\DatabaseConfiguration;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigurationTest extends TestCase
{
    public function testCredentialsAreSafelyEncodedInDatabaseUrl(): void
    {
        $configuration = new DatabaseConfiguration('db.internal', 3307, 'club_saas', 'user@example', 'p@ss:/?#');

        self::assertSame(
            'mysql://user%40example:p%40ss%3A%2F%3F%23@db.internal:3307/club_saas?serverVersion=10.6.0-MariaDB&charset=utf8mb4',
            $configuration->databaseUrl(),
        );
    }

    public function testIpv6HostIsWrappedForUrl(): void
    {
        $configuration = new DatabaseConfiguration('::1', 3306, 'saas', 'user', 'password');

        self::assertStringContainsString('@[::1]:3306/', $configuration->databaseUrl());
    }
}
