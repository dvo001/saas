<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Infrastructure\Support\SupportSessionService;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class SupportSessionController extends AbstractController
{
    #[Route('/v/{slug}/support/beenden', name: 'tenant_support_end', methods: ['POST'])]
    public function __invoke(Request $request, TenantContext $context, SupportSessionService $supportSessions, TokenStorageInterface $tokens): Response
    {
        if (!$this->isGranted('ROLE_SUPPORT_IMPERSONATION')) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('end_support', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
        }

        $session = $request->getSession();
        $supportSessions->end((string) $session->get(SupportSessionService::SESSION_KEY, ''), $context->get(), $request->getClientIp() ?? '');
        $session->remove(SupportSessionService::SESSION_KEY);
        $tokens->setToken(null);

        return $this->redirectToRoute('platform_tenants');
    }
}
