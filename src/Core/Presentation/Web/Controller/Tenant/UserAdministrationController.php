<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Application\Registration\UserAdministrationService;
use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Doctrine\Repository\TenantUserRepository;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserAdministrationController extends AbstractController
{
    #[Route('/v/{slug}/einstellungen/benutzer', name: 'tenant_users', methods: ['GET', 'POST'])]
    public function index(Request $request, TenantContext $context, TenantUserRepository $users, UserAdministrationService $administration): Response
    {
        $actor = $this->administrator();
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('invite_user', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            try {
                $role = TenantRole::tryFrom($request->request->getString('role')) ?? throw new \DomainException('Ungültige Rolle.');
                $administration->invite($actor, $request->request->getString('email'), $role, $request->getClientIp() ?? '');
                $this->addFlash('success', 'Die Einladung wurde versendet.');

                return $this->redirectToRoute('tenant_users', ['slug' => $context->get()->getSlug()]);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('tenant/users.html.twig', [
            'tenant' => $context->get(),
            'users' => $users->findActiveAndInvitedByTenant($context->get()),
            'roles' => array_values(array_filter(TenantRole::cases(), static fn (TenantRole $role): bool => $role !== TenantRole::Owner)),
            'error' => $error,
        ]);
    }

    #[Route('/v/{slug}/einstellungen/benutzer/{publicId}/{action}', name: 'tenant_user_action', requirements: ['action' => 'activate|deactivate|unlock'], methods: ['POST'])]
    public function action(string $publicId, string $action, Request $request, TenantContext $context, TenantUserRepository $users, UserAdministrationService $administration): Response
    {
        $actor = $this->administrator();
        if (!$this->isCsrfTokenValid('user_action_'.$publicId, $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
        }
        $target = $users->findByTenantAndPublicId($context->get(), $publicId) ?? throw $this->createNotFoundException();
        try {
            match ($action) {
                'activate' => $administration->setActive($actor, $target, true, $request->getClientIp() ?? ''),
                'deactivate' => $administration->setActive($actor, $target, false, $request->getClientIp() ?? ''),
                'unlock' => $administration->unlock($actor, $target, $request->getClientIp() ?? ''),
                default => throw $this->createNotFoundException(),
            };
            $this->addFlash('success', 'Die Benutzeränderung wurde gespeichert.');
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('tenant_users', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/einstellungen/benutzer/{publicId}/loeschen', name: 'tenant_user_delete', methods: ['GET', 'POST'])]
    public function delete(string $publicId, Request $request, TenantContext $context, TenantUserRepository $users, UserAdministrationService $administration): Response
    {
        $actor = $this->administrator();
        $target = $users->findByTenantAndPublicId($context->get(), $publicId) ?? throw $this->createNotFoundException();
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('delete_user_'.$publicId, $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            try {
                $administration->delete($actor, $target, $request->request->getString('password'), $request->getClientIp() ?? '');
                $this->addFlash('success', 'Der Benutzer wurde gelöscht und anonymisiert.');

                return $this->redirectToRoute('tenant_users', ['slug' => $context->get()->getSlug()]);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('tenant/delete_user.html.twig', ['tenant' => $context->get(), 'target' => $target, 'error' => $error]);
    }

    private function administrator(): TenantUser
    {
        $user = $this->getUser();
        if (!$user instanceof TenantUser || !in_array($user->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
