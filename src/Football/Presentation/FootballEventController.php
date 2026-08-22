<?php

declare(strict_types=1);

namespace App\Football\Presentation;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\TenantContext;
use App\Football\Application\FootballCompetitionService;
use App\Football\Application\FootballPdfService;
use App\Football\Application\FootballSetupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FootballEventController extends AbstractController
{
    #[Route('/v/{slug}/veranstaltungen/{id}/fussball', name: 'football_event', methods: ['GET', 'POST'])]
    public function index(string $id, Request $request, TenantContext $context, FootballSetupService $setup, FootballCompetitionService $competition): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); } $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('football_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            try {
                $ip = $request->getClientIp() ?? '';
                match ($request->request->getString('action')) {
                    'configure' => $setup->configure($user, $id, $request->request->all(), $ip),
                    'swiss_defaults' => $setup->createSwissDefaults($user, $id, $ip),
                    'category' => $setup->createCategory($user, $id, $request->request->all(), $ip),
                    'category_update' => $setup->updateCategory($user, $id, $request->request->getString('category_id'), $request->request->all(), $ip),
                    'team_category' => $setup->assignTeamCategory($user, $id, $request->request->getString('team'), $request->request->getString('category'), $ip),
                    'team_update' => $setup->updateTeam($user, $id, $request->request->getString('team'), $request->request->getString('name'), $request->request->getInt('team_number'), $request->request->getInt('lock_version'), $ip),
                    'groups' => $setup->generateGroups($user, $id, $request->request->getString('category'), $ip),
                    'team_group' => $setup->moveTeamToGroup($user, $id, $request->request->getString('team'), $request->request->getString('group'), $ip),
                    'field' => $setup->createField($user, $id, $request->request->getString('name'), $ip),
                    'period' => $setup->addFieldPeriod($user, $id, $request->request->getString('field'), $request->request->getString('period_type'), $request->request->getString('starts_at'), $request->request->getString('ends_at'), $request->request->getString('reason'), $ip),
                    'schedule' => $competition->generateSchedule($user, $id, $ip),
                    'move' => $competition->moveMatch($user, $id, $request->request->getString('match'), $request->request->getString('field'), $request->request->getString('starts_at'), $request->request->getInt('lock_version'), $request->request->getBoolean('acknowledge'), $ip),
                    'result' => $competition->saveResult($user, $id, $request->request->getString('match'), $request->request->all(), $ip),
                    'lot' => $competition->drawLot($user, $id, $request->request->getString('group'), $ip),
                    'cross_group_lot' => $competition->drawCrossGroupLot($user, $id, $request->request->getString('category'), $ip),
                    'finals' => $competition->createFinalRound($user, $id, $request->request->getString('category'), $ip),
                    'withdraw' => $competition->withdrawTeam($user, $id, $request->request->getString('team'), $request->request->getString('upcoming_action'), $request->request->getString('played_action'), $request->request->getString('reason'), $ip),
                    'publish' => $competition->publish($user, $id, $request->request->getString('type'), $ip),
                    'unpublish' => $competition->withdrawPublication($user, $id, $request->request->getString('type'), $ip),
                    default => throw new \DomainException('Unbekannte Fussballaktion.'),
                };
                $this->addFlash('success', 'Fussballdaten wurden gespeichert.');
                return $this->redirectToRoute('football_event', ['slug' => $context->get()->getSlug(), 'id' => $id]);
            } catch (\DomainException $exception) { $error = $exception->getMessage(); }
        }
        return $this->render('football/event.html.twig', ['tenant' => $context->get(), 'football' => $competition->workspace($user, $id), 'error' => $error]);
    }

    #[Route('/v/{slug}/veranstaltungen/{id}/fussball/revision', name: 'football_revision', methods: ['GET'])]
    public function revision(string $id, FootballCompetitionService $competition): JsonResponse
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); }
        return new JsonResponse(['revision' => $competition->revision($user, $id)]);
    }

    #[Route('/v/{slug}/veranstaltungen/{id}/fussball/pdf/{document}', name: 'football_pdf', requirements: ['document' => 'schedule|schedule_category|schedule_field|schedule_time|standings|finals|final_rankings'], methods: ['GET'])]
    public function pdf(string $id, string $document, FootballCompetitionService $competition, FootballPdfService $pdf): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); }
        return new Response($pdf->create($competition->workspace($user, $id), $document), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="fussball-'.$document.'.pdf"']);
    }
}
