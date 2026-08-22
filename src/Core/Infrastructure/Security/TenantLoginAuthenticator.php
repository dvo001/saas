<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Doctrine\Repository\TenantUserRepository;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class TenantLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantUserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuditLogger $audit,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && $request->attributes->get('_route') === 'tenant_login';
    }

    public function authenticate(Request $request): Passport
    {
        $email = mb_strtolower(trim((string) $request->request->get('email')));
        $request->getSession()->set('_security.last_username', $email);

        return new Passport(
            new UserBadge($email, function (string $identifier): TenantUser {
                $tenant = $this->context->get();
                $user = $this->users->findByTenantAndEmail($tenant, $identifier);
                if ($user === null) {
                    throw new UserNotFoundException();
                }
                if (!$user->isActive() || !$user->isEmailConfirmed() || !in_array($tenant->getStatus(), [TenantStatus::Trial, TenantStatus::Active], true)) {
                    throw new CustomUserMessageAuthenticationException('Dieses Benutzerkonto ist nicht aktiv.');
                }
                if ($user->isLocked(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                    throw new CustomUserMessageAuthenticationException('Das Benutzerkonto ist vorübergehend gesperrt.');
                }

                return $user;
            }),
            new PasswordCredentials((string) $request->request->get('password')),
            [
                new CsrfTokenBadge('tenant_login', $request->request->getString('_csrf_token')),
                new RememberMeBadge(),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();
        if (!$user instanceof TenantUser) {
            throw new \LogicException('Tenant authentication returned an unexpected user.');
        }

        $user->registerSuccessfulLogin();
        $this->entityManager->flush();
        $session = $request->getSession();
        $requiresChallenge = $user->requiresTwoFactor() || $user->hasTwoFactor();
        $session->set('two_factor_passed', !$requiresChallenge);
        $session->set('tenant_auth_started_at', time());
        $session->set('last_activity_at', time());
        $this->audit->log('auth.login_succeeded', 'tenant_user', $user->getPublicId(), $user->getTenant(), $user, [], $request->getClientIp());

        $route = $requiresChallenge
            ? ($user->hasTwoFactor() ? 'tenant_2fa_verify' : 'tenant_2fa_setup')
            : 'tenant_dashboard';

        return new RedirectResponse($this->urls->generate($route, ['slug' => $user->getTenant()->getSlug()]));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof BadCredentialsException) {
            $tenant = $this->context->getOrNull();
            $email = mb_strtolower(trim((string) $request->request->get('email')));
            $user = $tenant === null ? null : $this->users->findByTenantAndEmail($tenant, $email);
            if ($user !== null) {
                $user->registerFailedLogin(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                $this->entityManager->flush();
                $this->audit->log('auth.login_failed', 'tenant_user', $user->getPublicId(), $tenant, $user, [], $request->getClientIp());
            }
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('tenant_login', ['slug' => (string) $request->attributes->get('slug')]);
    }
}
