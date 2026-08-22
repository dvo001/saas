<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Auth;

use App\Core\Application\Registration\AccountRecoveryService;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountRecoveryController extends AbstractController
{
    #[Route('/v/{slug}/passwort-vergessen', name: 'password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request, TenantContext $context, AccountRecoveryService $recovery): Response
    {
        $sent = false;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('password_forgot', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            $recovery->requestPasswordReset($context->get(), $request->request->getString('email'), $request->getClientIp() ?? '');
            $sent = true;
        }

        return $this->render('auth/forgot_password.html.twig', ['tenant' => $context->get(), 'sent' => $sent]);
    }

    #[Route('/passwort-zuruecksetzen/{token}', name: 'password_reset', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request, AccountRecoveryService $recovery): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('password_reset_'.$token, $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            try {
                $user = $recovery->resetPassword($token, $request->request->getString('password'), $request->getClientIp() ?? '');

                return $this->redirectToRoute('tenant_login', ['slug' => $user->getTenant()->getSlug(), 'reset' => 1]);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('auth/reset_password.html.twig', ['token' => $token, 'error' => $error]);
    }

    #[Route('/v/{slug}/owner-entsperren', name: 'owner_unlock_request', methods: ['GET', 'POST'])]
    public function requestUnlock(Request $request, TenantContext $context, AccountRecoveryService $recovery): Response
    {
        $sent = false;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('owner_unlock_request', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            $recovery->requestOwnerUnlock($context->get(), $request->request->getString('email'), $request->getClientIp() ?? '');
            $sent = true;
        }

        return $this->render('auth/owner_unlock_request.html.twig', ['tenant' => $context->get(), 'sent' => $sent]);
    }

    #[Route('/konto-entsperren/{token}', name: 'owner_unlock', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function unlock(string $token, Request $request, AccountRecoveryService $recovery): Response
    {
        if (!$request->isMethod('POST')) { return $this->render('auth/token_confirmation.html.twig', ['title' => 'Ownerkonto entsperren', 'message' => 'Bestätige die Entsperrung dieses Ownerkontos.', 'button' => 'Konto entsperren', 'csrf_id' => 'owner_unlock_'.$token]); }
        if (!$this->isCsrfTokenValid('owner_unlock_'.$token, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.'); }
        try {
            $user = $recovery->unlockOwner($token, $request->getClientIp() ?? '');

            return $this->redirectToRoute('tenant_login', ['slug' => $user->getTenant()->getSlug(), 'unlocked' => 1]);
        } catch (\DomainException $exception) {
            return $this->render('registration/confirmation_error.html.twig', ['message' => $exception->getMessage()], new Response(status: Response::HTTP_BAD_REQUEST));
        }
    }
}
