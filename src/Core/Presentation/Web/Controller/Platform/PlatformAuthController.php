<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Security\SecretCipher;
use App\Core\Infrastructure\Security\Totp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class PlatformAuthController extends AbstractController
{
    #[Route('/platform/login', name: 'platform_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authentication): Response
    {
        return $this->render('platform/auth/login.html.twig', ['last_username' => $authentication->getLastUsername(), 'authentication_error' => $authentication->getLastAuthenticationError()]);
    }

    #[Route('/platform/2fa/einrichten', name: 'platform_2fa_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request, Totp $totp, SecretCipher $cipher, EntityManagerInterface $entityManager, AuditLogger $audit): Response
    {
        $admin = $this->admin();
        if ($admin->hasTwoFactor()) { return $this->redirectToRoute('platform_2fa_verify'); }
        $secret = (string) $request->getSession()->get('_platform_totp_setup_secret');
        if ($secret === '') { $secret = $totp->generateSecret(); $request->getSession()->set('_platform_totp_setup_secret', $secret); }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('platform_totp_setup', $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
            if ($totp->verify($secret, $request->request->getString('code'))) {
                $admin->enableTwoFactor($cipher->encrypt($secret));
                $entityManager->flush();
                $request->getSession()->remove('_platform_totp_setup_secret');
                $request->getSession()->set('platform_two_factor_passed', true);
                $request->getSession()->migrate(true);
                $audit->logPlatform('platform.auth.2fa_enabled', 'platform_admin', $admin->getPublicId(), $admin, [], null, $request->getClientIp());

                return $this->redirectToRoute('platform_dashboard');
            }
            $error = 'Der eingegebene Code ist nicht gültig.';
        }

        return $this->render('platform/auth/two_factor_setup.html.twig', ['secret' => $secret, 'uri' => $totp->provisioningUri($secret, $admin->getEmail(), 'Vereinssport Schweiz Platform'), 'error' => $error]);
    }

    #[Route('/platform/2fa', name: 'platform_2fa_verify', methods: ['GET', 'POST'])]
    public function verify(Request $request, Totp $totp, SecretCipher $cipher, AuditLogger $audit): Response
    {
        $admin = $this->admin();
        if (!$admin->hasTwoFactor()) { return $this->redirectToRoute('platform_2fa_setup'); }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('platform_totp_verify', $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
            $secret = $admin->getTotpSecretEncrypted() ?? throw new \LogicException('Missing platform 2FA secret.');
            if ($totp->verify($cipher->decrypt($secret), $request->request->getString('code'))) {
                $request->getSession()->set('platform_two_factor_passed', true);
                $request->getSession()->migrate(true);
                $audit->logPlatform('platform.auth.2fa_succeeded', 'platform_admin', $admin->getPublicId(), $admin, [], null, $request->getClientIp());

                return $this->redirectToRoute('platform_dashboard');
            }
            $error = 'Der eingegebene Code ist nicht gültig.';
        }

        return $this->render('platform/auth/two_factor_verify.html.twig', ['error' => $error]);
    }

    #[Route('/platform/logout', name: 'platform_logout', methods: ['POST'])]
    public function logout(Request $request, TokenStorageInterface $tokens): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('platform_logout', $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
        $tokens->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('platform_login');
    }

    private function admin(): PlatformAdmin
    {
        $admin = $this->getUser();
        if (!$admin instanceof PlatformAdmin) { throw $this->createAccessDeniedException(); }

        return $admin;
    }
}
