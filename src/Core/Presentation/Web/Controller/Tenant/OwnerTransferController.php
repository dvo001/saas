<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Application\Registration\OwnerTransferService;
use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Doctrine\Repository\TenantUserRepository;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OwnerTransferController extends AbstractController
{
    #[Route('/v/{slug}/einstellungen/ownerwechsel', name: 'owner_transfer', methods: ['GET', 'POST'])]
    public function initiate(Request $request, TenantContext $context, TenantUserRepository $users, OwnerTransferService $transfers): Response
    {
        $owner = $this->getUser();
        if (!$owner instanceof TenantUser || $owner->getTenantRole() !== TenantRole::Owner) {
            throw $this->createAccessDeniedException();
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('owner_transfer', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            $target = $users->findByTenantAndPublicId($context->get(), $request->request->getString('target'));
            try {
                if ($target === null) {
                    throw new \DomainException('Das Zielkonto wurde nicht gefunden.');
                }
                $transfers->initiate($owner, $target, $request->request->getString('password'), $request->getClientIp() ?? '');

                return $this->render('tenant/owner_transfer_pending.html.twig', ['target' => $target]);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('tenant/owner_transfer.html.twig', [
            'tenant' => $context->get(),
            'users' => array_values(array_filter($users->findActiveAndInvitedByTenant($context->get()), static fn (TenantUser $user): bool => $user->isActive() && $user->isEmailConfirmed() && $user->getTenantRole() !== TenantRole::Owner)),
            'error' => $error,
        ]);
    }

    #[Route('/ownerwechsel/bestaetigen/{token}', name: 'owner_transfer_confirm', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function confirm(string $token, Request $request, OwnerTransferService $transfers): Response
    {
        try {
            $tenant = $transfers->confirm($token, $request->getClientIp() ?? '');

            return $this->render('tenant/owner_transfer_confirmed.html.twig', ['tenant' => $tenant]);
        } catch (\DomainException $exception) {
            return $this->render('registration/confirmation_error.html.twig', ['message' => $exception->getMessage()], new Response(status: Response::HTTP_BAD_REQUEST));
        }
    }
}
