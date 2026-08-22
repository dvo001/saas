<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Application\Document\EventDocumentService;
use App\Core\Application\Event\EventService;
use App\Core\Domain\Event\EventStatus;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EventsController extends AbstractController
{
    #[Route('/v/{slug}/veranstaltungen', name: 'tenant_events', methods: ['GET', 'POST'])]
    public function index(Request $request, TenantContext $context, EventService $events, Connection $connection): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); } $error = null;
        if ($request->isMethod('POST')) { try { if (!$this->isCsrfTokenValid('event_create', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); } $id = $events->create($user, $request->request->all(), $request->getClientIp() ?? ''); return $this->redirectToRoute('tenant_event', ['slug' => $context->get()->getSlug(), 'id' => $id]); } catch (\DomainException $e) { $error = $e->getMessage(); } }
        return $this->render('tenant/events/index.html.twig', ['tenant' => $context->get(), 'events' => $events->listFor($user), 'modules' => $connection->fetchAllAssociative('SELECT code, name FROM sport_modules WHERE active = 1 ORDER BY name'), 'templates' => $events->creationOptions($user), 'error' => $error]);
    }

    #[Route('/v/{slug}/veranstaltungen/{id}', name: 'tenant_event', methods: ['GET'])]
    public function detail(string $id, TenantContext $context, EventService $events, EventDocumentService $documents): Response { $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); } return $this->render('tenant/events/detail.html.twig', ['tenant' => $context->get(), 'event' => $events->get($user, $id), 'documents' => $documents->listFor($user, $id)]); }

    #[Route('/v/{slug}/veranstaltungen/{id}/dokumente/{document}', name: 'tenant_event_document', methods: ['GET'])]
    public function document(string $id, string $document, EventDocumentService $documents): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); }
        try { $path = $documents->downloadPath($user, $id, $document); } catch (\DomainException) { throw $this->createNotFoundException(); }
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));
        return $response;
    }

    #[Route('/v/{slug}/veranstaltungen/{id}/status', name: 'tenant_event_status', methods: ['POST'])]
    public function status(string $id, Request $request, TenantContext $context, EventService $events): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser || !$this->isCsrfTokenValid('event_status_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        try { $events->transition($user, $id, EventStatus::from($request->request->getString('status')), $request->request->getString('reason'), $request->request->getBoolean('confirmed'), $request->getClientIp() ?? ''); $this->addFlash('success', 'Status wurde aktualisiert.'); } catch (\ValueError|\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('tenant_event', ['slug' => $context->get()->getSlug(), 'id' => $id]);
    }

    #[Route('/v/{slug}/veranstaltungen/{id}/duplizieren', name: 'tenant_event_duplicate', methods: ['POST'])]
    public function duplicate(string $id, Request $request, TenantContext $context, EventService $events): Response { $user = $this->getUser(); if (!$user instanceof TenantUser || !$this->isCsrfTokenValid('event_duplicate_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); } try { $newId = $events->duplicate($user, $id, $request->request->getString('name'), $request->getClientIp() ?? ''); return $this->redirectToRoute('tenant_event', ['slug' => $context->get()->getSlug(), 'id' => $newId]); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); return $this->redirectToRoute('tenant_event', ['slug' => $context->get()->getSlug(), 'id' => $id]); } }

    #[Route('/v/{slug}/veranstaltungen/{id}/loeschen', name: 'tenant_event_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, TenantContext $context, EventService $events): Response { $user = $this->getUser(); if (!$user instanceof TenantUser || !$this->isCsrfTokenValid('event_delete_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); } try { $events->deleteArchived($user, $id, $request->request->getBoolean('confirmed'), $request->getClientIp() ?? ''); $this->addFlash('success', 'Veranstaltung endgültig gelöscht.'); return $this->redirectToRoute('tenant_events', ['slug' => $context->get()->getSlug()]); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); return $this->redirectToRoute('tenant_event', ['slug' => $context->get()->getSlug(), 'id' => $id]); } }
}
