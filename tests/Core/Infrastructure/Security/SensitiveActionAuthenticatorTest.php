<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Security;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\SecretCipher;
use App\Core\Infrastructure\Security\SensitiveActionAuthenticator;
use App\Core\Infrastructure\Security\Totp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SensitiveActionAuthenticatorTest extends TestCase
{
    public function testSuccessfulPasswordAndTotpAuthenticationIsValidForTenMinutes(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('isPasswordValid')->with(self::isInstanceOf(TenantUser::class), 'correct')->willReturn(true);
        $cipher = new SecretCipher('test-secret');
        $totp = new Totp();
        $secret = $totp->generateSecret();
        $user = $this->user();
        $user->enableTwoFactor($cipher->encrypt($secret));
        $session = new Session(new MockArraySessionStorage());
        $authenticator = new SensitiveActionAuthenticator($hasher, $cipher, $totp);

        self::assertTrue($authenticator->authenticate($user, 'correct', $this->currentCode($totp, $secret), $session));
        $authentication = $session->get(SensitiveActionAuthenticator::SESSION_KEY);
        self::assertIsArray($authentication);
        self::assertIsInt($authentication['authenticated_at']);
        self::assertTrue($authenticator->isRecent($user, $session, $authentication['authenticated_at'] + 600));
        self::assertFalse($authenticator->isRecent($user, $session, $authentication['authenticated_at'] + 601));
        self::assertFalse($authenticator->isRecent($this->user('10000000-0000-7000-8000-000000000003'), $session, $authentication['authenticated_at']));
    }

    public function testInvalidPasswordDoesNotOpenTheSensitiveActionWindow(): void
    {
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(false);
        $session = new Session(new MockArraySessionStorage());
        $authenticator = new SensitiveActionAuthenticator($hasher, new SecretCipher('test-secret'), new Totp());

        self::assertFalse($authenticator->authenticate($this->user(), 'wrong', '000000', $session));
        self::assertFalse($authenticator->isRecent($this->user(), $session));
        $this->expectException(\DomainException::class);
        $authenticator->requireRecent($this->user(), $session);
    }

    private function user(string $publicId = '10000000-0000-7000-8000-000000000002'): TenantUser
    {
        $tenant = new Tenant('10000000-0000-7000-8000-000000000001', 'Testverein', 'testverein', TenantStatus::Active, TrialModule::Running, 'v1', new \DateTimeImmutable());

        return new TenantUser($tenant, $publicId, 'owner@example.ch', 'Owner', TenantRole::Owner, 'hash', true, true);
    }

    private function currentCode(Totp $totp, string $secret): string
    {
        $method = new \ReflectionMethod($totp, 'code');
        $code = $method->invoke($totp, $secret, intdiv(time(), 30));
        self::assertIsString($code);

        return $code;
    }
}
