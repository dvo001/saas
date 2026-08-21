<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TenantDashboardController extends AbstractController
{
    #[Route('/v/{slug}', name: 'tenant_dashboard', methods: ['GET'])]
    public function __invoke(TenantContext $context): Response
    {
        return $this->render('tenant/dashboard.html.twig', ['tenant' => $context->get()]);
    }
}
