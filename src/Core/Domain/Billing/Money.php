<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

final readonly class Money
{
    public function __construct(public int $minor, public string $currency = 'CHF')
    {
        if ($minor < 0) { throw new \DomainException('A monetary amount cannot be negative.'); }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) { throw new \DomainException('Invalid ISO currency.'); }
    }

    public static function fromDecimal(string $amount, string $currency = 'CHF'): self
    {
        $amount = trim(str_replace(',', '.', $amount));
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new \DomainException('Bitte einen Betrag mit höchstens zwei Dezimalstellen angeben.');
        }
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return new self(((int) $matches[1] * 100) + (int) $fraction, $currency);
    }

    public function add(self $other): self
    {
        $this->requireSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function percentage(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > 10000) { throw new \DomainException('Percentage basis points must be between 0 and 10000.'); }

        return new self((int) round($this->minor * $basisPoints / 10000, 0, PHP_ROUND_HALF_UP), $this->currency);
    }

    public function format(): string
    {
        return $this->currency.' '.number_format($this->minor / 100, 2, '.', "'");
    }

    private function requireSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) { throw new \DomainException('Currencies must match.'); }
    }
}
