<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Repository\TenantRepository;
use App\Core\Infrastructure\Support\SupportSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformTenantsController extends AbstractController
{
    #[Route('/platform/vereine', name: 'platform_tenants', methods: ['GET'])]
    public function index(TenantRepository $tenants): Response
    {
        return $this->render('platform/admin/tenants.html.twig', [
            'tenants' => $tenants->findForPlatformAdministration(),
        ]);
    }

    #[Route('/platform/vereine/{publicId}/support', name: 'platform_tenant_support', methods: ['GET', 'POST'])]
    public function support(string $publicId, Request $request, TenantRepository $tenants, SupportSessionService $supportSessions): Response
    {
        $admin = $this->getUser();
        if (!$admin instanceof PlatformAdmin) {
            throw $this->createAccessDeniedException();
        }
        $tenant = $tenants->findByPublicId($publicId) ?? throw $this->createNotFoundException('Verein nicht gefunden.');
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('support_'.$tenant->getPublicId(), $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            try {
                $rawToken = $supportSessions->start($admin, $tenant, $request->request->getString('reason'), $request->getClientIp() ?? '');
                $request->getSession()->set(SupportSessionService::SESSION_KEY, $rawToken);

                return $this->redirectToRoute('tenant_dashboard', ['slug' => $tenant->getSlug()]);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('platform/admin/tenant_support.html.twig', ['tenant' => $tenant, 'error' => $error]);
    }
}
