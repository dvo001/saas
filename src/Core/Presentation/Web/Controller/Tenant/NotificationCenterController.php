<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Application\Notification\NotificationService;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationCenterController extends AbstractController
{
    #[Route('/v/{slug}/benachrichtigungen', name: 'tenant_notifications', methods: ['GET'])]
    public function index(TenantContext $context, NotificationService $notifications): Response
    {
        $user = $this->getUser(); $tenant = $context->get();
        if (!$user instanceof TenantUser || $user->getId() === null || $tenant->getId() === null) { throw $this->createAccessDeniedException(); }
        return $this->render('tenant/notifications.html.twig', ['tenant' => $tenant, 'notifications' => $notifications->forUser($tenant->getId(), $user->getId())]);
    }
    #[Route('/v/{slug}/benachrichtigungen/{id}/gelesen', name: 'tenant_notification_read', methods: ['POST'])]
    public function read(string $id, Request $request, TenantContext $context, NotificationService $notifications): Response
    {
        $user = $this->getUser(); $tenant = $context->get();
        if (!$user instanceof TenantUser || $user->getId() === null || $tenant->getId() === null || !$this->isCsrfTokenValid('notification_read_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $notifications->markRead($tenant->getId(), $user->getId(), $id);
        return $this->redirectToRoute('tenant_notifications', ['slug' => $tenant->getSlug()]);
    }
}
