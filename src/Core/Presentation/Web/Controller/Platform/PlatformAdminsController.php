<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Application\Platform\PlatformAdminService;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Doctrine\Repository\PlatformAdminRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformAdminsController extends AbstractController
{
    #[Route('/platform/admins', name: 'platform_admins', methods: ['GET', 'POST'])]
    public function index(Request $request, PlatformAdminRepository $admins, PlatformAdminService $service): Response
    {
        $actor = $this->admin();
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('platform_admin_invite', $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
            try {
                $service->invite($actor, $request->request->getString('email'), $request->getClientIp() ?? '');
                $this->addFlash('success', 'Die Einladung wurde versendet.');

                return $this->redirectToRoute('platform_admins');
            } catch (\DomainException $exception) { $error = $exception->getMessage(); }
        }

        return $this->render('platform/admin/admins.html.twig', ['admins' => $admins->findCurrent(), 'error' => $error]);
    }

    #[Route('/platform/admins/{publicId}/{action}', name: 'platform_admin_action', requirements: ['action' => 'activate|deactivate|unlock'], methods: ['POST'])]
    public function action(string $publicId, string $action, Request $request, PlatformAdminRepository $admins, PlatformAdminService $service): Response
    {
        $actor = $this->admin();
        if (!$this->isCsrfTokenValid('platform_admin_action_'.$publicId, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
        $target = $admins->findByPublicId($publicId) ?? throw $this->createNotFoundException();
        try {
            match ($action) {
                'activate' => $service->setActive($actor, $target, true, $request->getClientIp() ?? ''),
                'deactivate' => $service->setActive($actor, $target, false, $request->getClientIp() ?? ''),
                'unlock' => $service->unlock($actor, $target, $request->getClientIp() ?? ''),
                default => throw $this->createNotFoundException(),
            };
            $this->addFlash('success', 'Plattformadmin aktualisiert.');
        } catch (\DomainException $exception) { $this->addFlash('danger', $exception->getMessage()); }

        return $this->redirectToRoute('platform_admins');
    }

    #[Route('/platform/einladung/{token}', name: 'platform_invitation_accept', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function accept(string $token, Request $request, PlatformAdminService $service): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('platform_invitation_'.$token, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
            try {
                $service->accept($token, $request->request->getString('display_name'), $request->request->getString('password'), $request->getClientIp() ?? '');

                return $this->redirectToRoute('platform_login', ['invited' => 1]);
            } catch (\DomainException $exception) { $error = $exception->getMessage(); }
        }

        return $this->render('platform/auth/invitation.html.twig', ['token' => $token, 'error' => $error]);
    }

    #[Route('/platform/admins/{publicId}/loeschen', name: 'platform_admin_delete', methods: ['GET', 'POST'])]
    public function delete(string $publicId, Request $request, PlatformAdminRepository $admins, PlatformAdminService $service): Response
    {
        $actor = $this->admin();
        $target = $admins->findByPublicId($publicId) ?? throw $this->createNotFoundException();
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('platform_admin_delete_'.$publicId, $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $service->delete($actor, $target, $request->request->getString('password'), $request->getClientIp() ?? '');
                $this->addFlash('success', 'Der Plattformadmin wurde gelöscht und anonymisiert.');

                return $this->redirectToRoute('platform_admins');
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('platform/admin/delete_admin.html.twig', ['target' => $target, 'error' => $error]);
    }

    private function admin(): PlatformAdmin
    {
        $admin = $this->getUser();
        if (!$admin instanceof PlatformAdmin) { throw $this->createAccessDeniedException(); }

        return $admin;
    }
}
