<?php

declare(strict_types=1);

namespace App\Core\Application\Export;

final readonly class CsvCellSanitizer
{
    public function escape(mixed $value): string
    {
        $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
        return preg_match('/^[=+\-@\t\r]/u', $text) === 1 ? "'".$text : $text;
    }
}
