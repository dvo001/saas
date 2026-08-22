<?php

declare(strict_types=1);

namespace App\Football\Domain;

final class ScheduleGenerationException extends \DomainException
{
    /** @param list<string> $conflicts */
    public function __construct(private readonly array $conflicts)
    {
        parent::__construct('Der Spielplan ist nicht machbar: '.implode(' ', $conflicts));
    }

    /** @return list<string> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }
}
