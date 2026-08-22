<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Application\Document\TenantLogoService;
use App\Core\Infrastructure\Tenancy\TenantContext;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TenantDashboardController extends AbstractController
{
    #[Route('/v/{slug}', name: 'tenant_dashboard', methods: ['GET'])]
    public function __invoke(TenantContext $context): Response
    {
        return $this->render('tenant/dashboard.html.twig', ['tenant' => $context->get()]);
    }

    #[Route('/v/{slug}/logo', name: 'tenant_logo_upload', methods: ['POST'])]
    public function logo(Request $request, TenantContext $context, TenantLogoService $logos): Response
    {
        $user = $this->getUser();
        if (!$user instanceof TenantUser || !$this->isCsrfTokenValid('tenant_logo', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $file = $request->files->get('logo');
        try {
            if (!$file instanceof UploadedFile) { throw new \DomainException('Bitte eine PNG- oder JPEG-Datei auswählen.'); }
            $logos->upload($user, $file, $request->getClientIp() ?? '');
            $this->addFlash('success', 'Das Vereinslogo wurde aktualisiert.');
        } catch (\DomainException $exception) { $this->addFlash('danger', $exception->getMessage()); }
        return $this->redirectToRoute('tenant_dashboard', ['slug' => $context->get()->getSlug()]);
    }
}
