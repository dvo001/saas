<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Installation;

use App\Core\Infrastructure\Installation\DatabaseConfiguration;
use App\Core\Infrastructure\Installation\InstallationInput;
use PHPUnit\Framework\TestCase;

final class InstallationInputTest extends TestCase
{
    public function testValidInputPassesValidation(): void
    {
        $input = new InstallationInput(
            new DatabaseConfiguration('localhost', 3306, 'saas', 'saas_user', 'secret'),
            'Vereinssport Schweiz',
            'https://sport.example.ch',
            'admin@example.ch',
            'a-long-password',
            'null://null',
        );

        self::assertSame([], $input->validate());
    }

    public function testInsecureAndIncompleteInputIsRejected(): void
    {
        $input = new InstallationInput(
            new DatabaseConfiguration('', 99999, 'bad name', '', ''),
            '',
            'http://insecure.example',
            'not-an-email',
            'short',
            'invalid',
        );

        self::assertGreaterThanOrEqual(8, count($input->validate()));
    }
}
