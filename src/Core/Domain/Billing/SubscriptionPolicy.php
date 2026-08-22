<?php

declare(strict_types=1);

namespace App\Core\Domain\Billing;

final class SubscriptionPolicy
{
    public const TERM_MONTHS = 12;
    public const RENEWAL_NOTICE_DAYS = 30;
    public const RENEWAL_CHANGE_DEADLINE_DAYS = 7;
    public const MAX_MODULE_RETENTION_DAYS = 365;
    public const PAID_ACCOUNT_RETENTION_DAYS = 90;

    public function annualEnd(\DateTimeImmutable $startsAt): \DateTimeImmutable
    {
        return $startsAt->add(new \DateInterval('P'.self::TERM_MONTHS.'M'));
    }

    public function addOnEnd(\DateTimeImmutable $purchasedAt, \DateTimeImmutable $subscriptionEndsAt): \DateTimeImmutable
    {
        if ($purchasedAt >= $subscriptionEndsAt) {
            throw new \DomainException('An add-on requires a running main subscription.');
        }

        return $subscriptionEndsAt;
    }

    public function renewalNoticeAt(\DateTimeImmutable $endsAt): \DateTimeImmutable
    {
        return $endsAt->sub(new \DateInterval('P'.self::RENEWAL_NOTICE_DAYS.'D'));
    }

    public function mayChangeRenewal(\DateTimeImmutable $now, \DateTimeImmutable $endsAt): bool
    {
        return $now <= $endsAt->sub(new \DateInterval('P'.self::RENEWAL_CHANGE_DEADLINE_DAYS.'D'));
    }

    public function moduleArchiveUntil(\DateTimeImmutable $moduleEndsAt, int $requestedDays, \DateTimeImmutable $accountExistsUntil): \DateTimeImmutable
    {
        if ($requestedDays < 0 || $requestedDays > self::MAX_MODULE_RETENTION_DAYS) {
            throw new \DomainException('Module retention must be between 0 and 365 days.');
        }

        $requestedUntil = $moduleEndsAt->add(new \DateInterval('P'.$requestedDays.'D'));

        return $requestedUntil < $accountExistsUntil ? $requestedUntil : $accountExistsUntil;
    }

    public function paidAccountRetentionUntil(\DateTimeImmutable $endsAt): \DateTimeImmutable
    {
        return $endsAt->add(new \DateInterval('P'.self::PAID_ACCOUNT_RETENTION_DAYS.'D'));
    }
}
