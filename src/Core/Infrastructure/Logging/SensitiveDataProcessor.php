<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class SensitiveDataProcessor implements ProcessorInterface
{
    private const REDACTED = '[redacted]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->sanitize($record->context));
    }

    /** @param array<mixed> $values
     *  @return array<mixed>
     */
    private function sanitize(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|secret|token|authorization|cookie|dsn/i', $key)) {
                $values[$key] = self::REDACTED;
                continue;
            }

            if ($value instanceof \Throwable) {
                $values[$key] = [
                    'class' => $value::class,
                    'code' => $value->getCode(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->sanitize($value);
            }
        }

        return $values;
    }
}
