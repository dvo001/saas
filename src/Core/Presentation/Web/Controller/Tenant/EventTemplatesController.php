<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Application\Event\EventTemplateService;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EventTemplatesController extends AbstractController
{
    #[Route('/v/{slug}/veranstaltungsvorlagen', name: 'tenant_event_templates', methods: ['GET', 'POST'])]
    public function index(Request $request, TenantContext $context, EventTemplateService $templates, Connection $connection): Response { $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); } $error = null; if ($request->isMethod('POST')) { if (!$this->isCsrfTokenValid('tenant_event_template', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); } try { $templates->saveTenant($user, $request->request->getString('name'), $request->request->getString('module'), $request->request->getString('configuration'), $request->getClientIp() ?? ''); return $this->redirectToRoute('tenant_event_templates', ['slug' => $context->get()->getSlug()]); } catch (\DomainException $e) { $error = $e->getMessage(); } } return $this->render('tenant/events/templates.html.twig', ['tenant' => $context->get(), 'templates' => $templates->tenantOverview($context->get()->getId() ?? 0), 'modules' => $connection->fetchAllAssociative('SELECT code, name FROM sport_modules WHERE active = 1 ORDER BY name'), 'error' => $error]); }
    #[Route('/v/{slug}/veranstaltungsvorlagen/{id}/loeschen', name: 'tenant_event_template_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, TenantContext $context, EventTemplateService $templates): Response { $user = $this->getUser(); if (!$user instanceof TenantUser || !$this->isCsrfTokenValid('tenant_template_delete_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); } try { $templates->deleteTenant($user, $id, $request->getClientIp() ?? ''); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); } return $this->redirectToRoute('tenant_event_templates', ['slug' => $context->get()->getSlug()]); }
}
