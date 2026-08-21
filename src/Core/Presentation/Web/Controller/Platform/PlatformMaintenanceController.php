<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Maintenance\MaintenanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformMaintenanceController extends AbstractController
{
    #[Route('/platform/wartung', name: 'platform_maintenance', methods: ['GET', 'POST'])]
    public function index(Request $request, MaintenanceService $maintenance): Response
    {
        $admin = $this->admin();
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('schedule_maintenance', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            try {
                if ($request->request->getBoolean('immediate')) {
                    $startsAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                } else {
                    $startsAt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $request->request->getString('starts_at'), new \DateTimeZone('Europe/Zurich'));
                    if ($startsAt === false) {
                        throw new \DomainException('Bitte einen gültigen Wartungsstart angeben.');
                    }
                    $startsAt = $startsAt->setTimezone(new \DateTimeZone('UTC'));
                }
                $maintenance->schedule($admin, $startsAt, $request->request->getInt('duration'), $request->request->getString('message'), $request->getClientIp() ?? '');
                $this->addFlash('success', $request->request->getBoolean('immediate') ? 'Die Notfallwartung ist aktiv.' : 'Das Wartungsfenster wurde geplant.');

                return $this->redirectToRoute('platform_maintenance');
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('platform/admin/maintenance.html.twig', [
            'active' => $maintenance->active(),
            'next' => $maintenance->next(),
            'history' => $maintenance->history(),
            'error' => $error,
        ]);
    }

    #[Route('/platform/wartung/{publicId}/absagen', name: 'platform_maintenance_cancel', methods: ['POST'])]
    public function cancel(string $publicId, Request $request, MaintenanceService $maintenance): Response
    {
        if (!$this->isCsrfTokenValid('cancel_maintenance_'.$publicId, $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
        }
        try {
            $maintenance->cancel($this->admin(), $publicId, $request->getClientIp() ?? '');
            $this->addFlash('success', 'Das Wartungsfenster wurde abgesagt.');
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('platform_maintenance');
    }

    private function admin(): PlatformAdmin
    {
        $admin = $this->getUser();
        if (!$admin instanceof PlatformAdmin) {
            throw $this->createAccessDeniedException();
        }

        return $admin;
    }
}
