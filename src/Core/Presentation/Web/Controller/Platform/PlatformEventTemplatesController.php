<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Application\Event\EventTemplateService;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformEventTemplatesController extends AbstractController
{
    #[Route('/platform/veranstaltungsvorlagen', name: 'platform_event_templates', methods: ['GET', 'POST'])]
    public function index(Request $request, EventTemplateService $templates, Connection $connection): Response
    {
        $admin = $this->getUser(); if (!$admin instanceof PlatformAdmin) { throw $this->createAccessDeniedException(); } $error = null;
        if ($request->isMethod('POST')) { if (!$this->isCsrfTokenValid('platform_event_templates', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); } try { if ($request->request->getString('action') === 'defaults') { $templates->saveDefaults($admin, $request->request->getString('module'), $request->request->getString('configuration'), $request->getClientIp() ?? ''); } else { $templates->saveGlobal($admin, $request->request->getString('name'), $request->request->getString('module'), $request->request->getString('configuration'), $request->request->getString('template_id') ?: null, $request->getClientIp() ?? ''); } $this->addFlash('success', 'Version wurde gespeichert.'); return $this->redirectToRoute('platform_event_templates'); } catch (\DomainException $e) { $error = $e->getMessage(); } }
        return $this->render('platform/events/templates.html.twig', ['templates' => $templates->globalOverview(), 'modules' => $connection->fetchAllAssociative('SELECT code, name FROM sport_modules ORDER BY name'), 'error' => $error]);
    }
    #[Route('/platform/veranstaltungsvorlagen/{id}/status', name: 'platform_event_template_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request, EventTemplateService $templates): Response { $admin = $this->getUser(); if (!$admin instanceof PlatformAdmin || !$this->isCsrfTokenValid('template_toggle_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); } $templates->toggleGlobal($admin, $id, $request->getClientIp() ?? ''); return $this->redirectToRoute('platform_event_templates'); }
}
