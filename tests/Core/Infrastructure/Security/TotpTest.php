<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Security;

use App\Core\Infrastructure\Security\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function testVerifiesRfc6238CompatibleSixDigitCode(): void
    {
        $totp = new Totp();

        self::assertTrue($totp->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59));
        self::assertFalse($totp->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287083', 59));
    }

    public function testProvisioningUriContainsNoUnexpectedParameters(): void
    {
        $uri = (new Totp())->provisioningUri('ABCDEF234567', 'owner@example.ch', 'Vereinssport Schweiz');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=ABCDEF234567', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
