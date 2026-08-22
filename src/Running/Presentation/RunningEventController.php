<?php

declare(strict_types=1);

namespace App\Running\Presentation;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\TenantContext;
use App\Running\Application\RunningCategoryService;
use App\Running\Application\RunningCompetitionService;
use App\Running\Application\RunningPdfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RunningEventController extends AbstractController
{
    #[Route('/v/{slug}/veranstaltungen/{id}/lauf', name: 'running_event', methods: ['GET', 'POST'])]
    public function index(string $id, Request $request, TenantContext $context, RunningCompetitionService $competition, RunningCategoryService $categories): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); } $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('running_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            try {
                $action = $request->request->getString('action');
                match ($action) {
                    'configure' => $competition->configure($user, $id, $request->request->all(), $request->getClientIp() ?? ''),
                    'category' => $categories->create($user, $id, $request->request->getString('name'), $request->request->getInt('year_from'), $request->request->getInt('year_to'), $request->request->getString('gender'), $request->getClientIp() ?? ''),
                    'category_update' => $categories->update($user, $id, $request->request->getString('category_id'), $request->request->getString('name'), $request->request->getInt('year_from'), $request->request->getInt('year_to'), $request->request->getString('gender'), $request->request->getInt('lock_version'), $request->getClientIp() ?? ''),
                    'start_numbers' => $competition->saveStartNumbers($user, $id, $request->request->all('numbers'), $request->request->all('number_versions'), $request->getClientIp() ?? ''),
                    'qualification' => $competition->saveQualification($user, $id, $request->request->all('times'), $request->request->all('qualification_versions'), $request->getClientIp() ?? ''),
                    'finalists' => $competition->confirmFinalists($user, $id, array_values(array_map('strval', $request->request->all('finalists'))), $request->getClientIp() ?? ''),
                    'reset_finalists' => $competition->resetFinalists($user, $id, $request->request->getString('reason'), $request->getClientIp() ?? ''),
                    'finals' => $competition->saveFinals($user, $id, $request->request->all('final_times'), $request->request->all('final_versions'), $request->getClientIp() ?? ''),
                    default => throw new \DomainException('Unbekannte Aktion.'),
                };
                $this->addFlash('success', 'Laufdaten wurden gespeichert.');
                return $this->redirectToRoute('running_event', ['slug' => $context->get()->getSlug(), 'id' => $id]);
            } catch (\DomainException $exception) { $error = $exception->getMessage(); }
        }
        return $this->render('running/event.html.twig', ['tenant' => $context->get(), 'run' => $competition->workspace($user, $id), 'categories' => $categories->list($user, $id), 'error' => $error]);
    }

    #[Route('/v/{slug}/veranstaltungen/{id}/lauf/druck/{document}', name: 'running_print', requirements: ['document' => 'sheets|qualification|finalists|final'], methods: ['GET'])]
    public function print(string $id, string $document, TenantContext $context, RunningCompetitionService $competition): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); }
        return $this->render('running/print.html.twig', ['tenant' => $context->get(), 'run' => $competition->workspace($user, $id), 'document' => $document]);
    }

    #[Route('/v/{slug}/veranstaltungen/{id}/lauf/revision', name: 'running_revision', methods: ['GET'])]
    public function revision(string $id, Request $request, RunningCompetitionService $competition): JsonResponse
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); }
        $revision = $competition->revision($user, $id); $response = new JsonResponse(['revision' => $revision]); $response->setEtag($revision); $response->setPrivate(); $response->isNotModified($request); return $response;
    }

    #[Route('/v/{slug}/veranstaltungen/{id}/lauf/pdf/{document}', name: 'running_pdf', requirements: ['document' => 'sheets|qualification|finalists|final'], methods: ['GET'])]
    public function pdf(string $id, string $document, RunningCompetitionService $competition, RunningPdfService $pdf): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); }
        return new Response($pdf->create($competition->workspace($user, $id), $document), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="lauf-'.$document.'.pdf"']);
    }
}
