<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Auth;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Security\SecretCipher;
use App\Core\Infrastructure\Security\Totp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TwoFactorController extends AbstractController
{
    #[Route('/v/{slug}/2fa/einrichten', name: 'tenant_2fa_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request, Totp $totp, SecretCipher $cipher, EntityManagerInterface $entityManager, AuditLogger $audit): Response
    {
        $user = $this->getUser();
        if (!$user instanceof TenantUser) {
            throw $this->createAccessDeniedException();
        }
        if ($user->hasTwoFactor()) {
            return $this->redirectToRoute('tenant_2fa_verify', ['slug' => $user->getTenant()->getSlug()]);
        }

        $session = $request->getSession();
        $secret = (string) $session->get('_totp_setup_secret');
        if ($secret === '') {
            $secret = $totp->generateSecret();
            $session->set('_totp_setup_secret', $secret);
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('totp_setup', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            if ($totp->verify($secret, $request->request->getString('code'))) {
                $user->enableTwoFactor($cipher->encrypt($secret));
                $entityManager->flush();
                $session->remove('_totp_setup_secret');
                $session->set('two_factor_passed', true);
                $session->migrate(true);
                $audit->log('auth.2fa_enabled', 'tenant_user', $user->getPublicId(), $user->getTenant(), $user, [], $request->getClientIp());

                return $this->redirectToRoute('tenant_dashboard', ['slug' => $user->getTenant()->getSlug()]);
            }
            $error = 'Der eingegebene Code ist nicht gültig.';
        }

        $provisioningUri = $totp->provisioningUri($secret, $user->getEmail(), 'Vereinssport Schweiz');

        return $this->render('auth/two_factor_setup.html.twig', [
            'secret' => $secret,
            'provisioning_uri' => $provisioningUri,
            'qr_code_data_uri' => $totp->qrCodeDataUri($provisioningUri),
            'error' => $error,
        ]);
    }

    #[Route('/v/{slug}/2fa', name: 'tenant_2fa_verify', methods: ['GET', 'POST'])]
    public function verify(Request $request, Totp $totp, SecretCipher $cipher, AuditLogger $audit): Response
    {
        $user = $this->getUser();
        if (!$user instanceof TenantUser || !$user->hasTwoFactor()) {
            return $this->redirectToRoute('tenant_2fa_setup', ['slug' => $request->attributes->getString('slug')]);
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('totp_verify', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            $encrypted = $user->getTotpSecretEncrypted() ?? throw new \LogicException('Missing two-factor secret.');
            if ($totp->verify($cipher->decrypt($encrypted), $request->request->getString('code'))) {
                $request->getSession()->set('two_factor_passed', true);
                $request->getSession()->migrate(true);
                $audit->log('auth.2fa_succeeded', 'tenant_user', $user->getPublicId(), $user->getTenant(), $user, [], $request->getClientIp());

                return $this->redirectToRoute('tenant_dashboard', ['slug' => $user->getTenant()->getSlug()]);
            }
            $error = 'Der eingegebene Code ist nicht gültig.';
        }

        return $this->render('auth/two_factor_verify.html.twig', ['error' => $error]);
    }

    #[Route('/v/{slug}/2fa/deaktivieren', name: 'tenant_2fa_disable', methods: ['GET', 'POST'])]
    public function disable(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager, AuditLogger $audit): Response
    {
        $user = $this->getUser();
        if (!$user instanceof TenantUser || !$user->hasTwoFactor()) {
            throw $this->createNotFoundException();
        }
        if ($user->requiresTwoFactor()) {
            throw $this->createAccessDeniedException('Für diese Rolle ist 2FA obligatorisch.');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('totp_disable', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            if (!$passwordHasher->isPasswordValid($user, $request->request->getString('password'))) {
                $error = 'Das aktuelle Passwort ist nicht korrekt.';
            } else {
                $user->disableTwoFactor();
                $entityManager->flush();
                $request->getSession()->set('two_factor_passed', true);
                $request->getSession()->migrate(true);
                $audit->log('auth.2fa_disabled', 'tenant_user', $user->getPublicId(), $user->getTenant(), $user, [], $request->getClientIp());

                return $this->redirectToRoute('tenant_dashboard', ['slug' => $user->getTenant()->getSlug()]);
            }
        }

        return $this->render('auth/two_factor_disable.html.twig', ['error' => $error]);
    }
}
