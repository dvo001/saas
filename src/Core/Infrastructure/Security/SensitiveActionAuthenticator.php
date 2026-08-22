<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class SensitiveActionAuthenticator
{
    public const SESSION_KEY = 'tenant_sensitive_action_authenticated_at';
    private const MAX_AGE_SECONDS = 600;

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private SecretCipher $cipher,
        private Totp $totp,
    ) {}

    public function authenticate(TenantUser $user, string $password, string $code, SessionInterface $session): bool
    {
        $session->remove(self::SESSION_KEY);
        $secret = $user->getTotpSecretEncrypted();
        if (!$this->passwordHasher->isPasswordValid($user, $password) || $secret === null) {
            return false;
        }

        try {
            $validCode = $this->totp->verify($this->cipher->decrypt($secret), $code);
        } catch (\InvalidArgumentException|\RuntimeException) {
            return false;
        }

        if (!$validCode) {
            return false;
        }

        $session->set(self::SESSION_KEY, ['user' => $user->getPublicId(), 'authenticated_at' => time()]);
        $session->migrate(true);

        return true;
    }

    public function isRecent(TenantUser $user, SessionInterface $session, ?int $now = null): bool
    {
        $authentication = $session->get(self::SESSION_KEY);
        $now ??= time();

        return is_array($authentication)
            && ($authentication['user'] ?? null) === $user->getPublicId()
            && is_int($authentication['authenticated_at'] ?? null)
            && $authentication['authenticated_at'] <= $now
            && $now - $authentication['authenticated_at'] <= self::MAX_AGE_SECONDS;
    }

    public function requireRecent(TenantUser $user, SessionInterface $session): void
    {
        if (!$this->isRecent($user, $session)) {
            throw new \DomainException('Bitte bestätige zuerst erneut Passwort und Zwei-Faktor-Code.');
        }
    }
}
