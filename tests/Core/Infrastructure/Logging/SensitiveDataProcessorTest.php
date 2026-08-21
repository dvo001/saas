<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Logging;

use App\Core\Infrastructure\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SensitiveDataProcessorTest extends TestCase
{
    public function testSensitiveContextAndExceptionMessagesAreNotLogged(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Error,
            'Failure',
            [
                'password' => 'very-secret',
                'nested' => ['api_token' => 'token-value'],
                'exception' => new \RuntimeException('SQL containing private data'),
            ],
        );

        $processed = (new SensitiveDataProcessor())($record);

        self::assertSame('[redacted]', $processed->context['password']);
        self::assertSame('[redacted]', $processed->context['nested']['api_token']);
        self::assertSame(\RuntimeException::class, $processed->context['exception']['class']);
        self::assertStringNotContainsString('private data', json_encode($processed->context, JSON_THROW_ON_ERROR));
    }
}
