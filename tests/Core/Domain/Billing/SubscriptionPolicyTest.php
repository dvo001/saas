<?php

declare(strict_types=1);

namespace App\Tests\Core\Domain\Billing;

use App\Core\Domain\Billing\SubscriptionPolicy;
use PHPUnit\Framework\TestCase;

final class SubscriptionPolicyTest extends TestCase
{
    private SubscriptionPolicy $policy;

    protected function setUp(): void { $this->policy = new SubscriptionPolicy(); }

    public function testAnnualTermAndAddOnUseMainSubscriptionEnd(): void
    {
        $start = new \DateTimeImmutable('2026-07-15 12:00:00', new \DateTimeZone('UTC'));
        $end = $this->policy->annualEnd($start);

        self::assertSame('2027-07-15 12:00:00', $end->format('Y-m-d H:i:s'));
        self::assertSame($end, $this->policy->addOnEnd($start->add(new \DateInterval('P6M')), $end));
    }

    public function testExpiredMainSubscriptionCannotReceiveAddOn(): void
    {
        $end = new \DateTimeImmutable('2027-01-01');
        $this->expectException(\DomainException::class);
        $this->policy->addOnEnd($end, $end);
    }

    public function testRenewalCanOnlyBeChangedUntilSevenDaysBeforeEnd(): void
    {
        $end = new \DateTimeImmutable('2027-12-31 12:00:00');
        self::assertTrue($this->policy->mayChangeRenewal(new \DateTimeImmutable('2027-12-24 12:00:00'), $end));
        self::assertFalse($this->policy->mayChangeRenewal(new \DateTimeImmutable('2027-12-24 12:00:01'), $end));
        self::assertSame('2027-12-01', $this->policy->renewalNoticeAt($end)->format('Y-m-d'));
    }

    public function testModuleRetentionIsCappedByAccountLifetime(): void
    {
        $moduleEnd = new \DateTimeImmutable('2027-01-01');
        $accountEnd = new \DateTimeImmutable('2027-04-01');
        self::assertSame($accountEnd, $this->policy->moduleArchiveUntil($moduleEnd, 365, $accountEnd));
        self::assertSame('2027-04-01', $this->policy->paidAccountRetentionUntil($moduleEnd)->format('Y-m-d'));
    }
}
