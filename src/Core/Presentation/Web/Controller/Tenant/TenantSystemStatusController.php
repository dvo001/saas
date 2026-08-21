<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\System\SystemStatusService;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TenantSystemStatusController extends AbstractController
{
    #[Route('/v/{slug}/systemstatus', name: 'tenant_system_status', methods: ['GET'])]
    public function __invoke(TenantContext $context, SystemStatusService $status): Response
    {
        $viewer = $this->getUser();
        if (!$viewer instanceof TenantUser || !in_array($viewer->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true)) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('tenant/system_status.html.twig', ['tenant' => $context->get(), 'status' => $status->snapshot()]);
    }
}
