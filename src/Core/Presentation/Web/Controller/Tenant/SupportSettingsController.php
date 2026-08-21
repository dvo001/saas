<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SupportSettingsController extends AbstractController
{
    #[Route('/v/{slug}/einstellungen/support', name: 'tenant_support_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, TenantContext $context, EntityManagerInterface $entityManager, AuditLogger $audit): Response
    {
        $owner = $this->getUser();
        if (!$owner instanceof TenantUser || $owner->getTenantRole() !== TenantRole::Owner) {
            throw $this->createAccessDeniedException('Nur der Owner kann den Supportzugriff ändern.');
        }
        $tenant = $context->get();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('support_settings', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            $enabled = $request->request->getBoolean('enabled');
            $tenant->setSupportImpersonationEnabled($enabled);
            $entityManager->flush();
            $audit->log('support.setting_changed', 'tenant', $tenant->getPublicId(), $tenant, $owner, ['enabled' => $enabled], $request->getClientIp() ?? '');
            $this->addFlash('success', 'Die Supportzugriffs-Einstellung wurde gespeichert.');

            return $this->redirectToRoute('tenant_support_settings', ['slug' => $tenant->getSlug()]);
        }

        return $this->render('tenant/support_settings.html.twig', ['tenant' => $tenant]);
    }
}
