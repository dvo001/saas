<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Repository\PlatformAdminRepository;
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
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class PlatformLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private readonly PlatformAdminRepository $admins,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuditLogger $audit,
    ) {}

    public function supports(Request $request): bool { return $request->isMethod('POST') && $request->attributes->get('_route') === 'platform_login'; }

    public function authenticate(Request $request): Passport
    {
        $email = mb_strtolower(trim($request->request->getString('email')));
        $request->getSession()->set('_security.last_username', $email);

        return new Passport(
            new UserBadge($email, function (string $identifier): PlatformAdmin {
                $admin = $this->admins->findByEmail($identifier);
                if ($admin === null) { throw new UserNotFoundException(); }
                if (!$admin->isActive() || !$admin->isEmailConfirmed()) { throw new CustomUserMessageAuthenticationException('Dieses Plattformkonto ist nicht aktiv.'); }
                if ($admin->isLocked(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) { throw new CustomUserMessageAuthenticationException('Das Plattformkonto ist gesperrt und muss durch einen anderen Plattformadmin entsperrt werden.'); }

                return $admin;
            }),
            new PasswordCredentials($request->request->getString('password')),
            [new CsrfTokenBadge('platform_login', $request->request->getString('_csrf_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $admin = $token->getUser();
        if (!$admin instanceof PlatformAdmin) { throw new \LogicException('Unexpected platform user.'); }
        $admin->registerSuccessfulLogin();
        $this->entityManager->flush();
        $request->getSession()->set('platform_two_factor_passed', false);
        $request->getSession()->set('platform_last_activity_at', time());
        $this->audit->logPlatform('platform.auth.login_succeeded', 'platform_admin', $admin->getPublicId(), $admin, [], null, $request->getClientIp());

        return new RedirectResponse($this->urls->generate($admin->hasTwoFactor() ? 'platform_2fa_verify' : 'platform_2fa_setup'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof BadCredentialsException) {
            $admin = $this->admins->findByEmail($request->request->getString('email'));
            if ($admin !== null) {
                $admin->registerFailedLogin(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                $this->entityManager->flush();
                $this->audit->logPlatform('platform.auth.login_failed', 'platform_admin', $admin->getPublicId(), $admin, [], null, $request->getClientIp());
            }
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string { return $this->urls->generate('platform_login'); }
}
