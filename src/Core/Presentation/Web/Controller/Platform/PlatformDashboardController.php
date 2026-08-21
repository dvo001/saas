<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformDashboardController extends AbstractController
{
    #[Route('/platform', name: 'platform_dashboard', methods: ['GET'])]
    public function __invoke(Connection $connection): Response
    {
        return $this->render('platform/admin/dashboard.html.twig', [
            'counts' => [
                'active' => (int) $connection->fetchOne("SELECT COUNT(*) FROM tenants WHERE status = 'active'"),
                'trial' => (int) $connection->fetchOne("SELECT COUNT(*) FROM tenants WHERE status = 'trial'"),
                'suspended' => (int) $connection->fetchOne("SELECT COUNT(*) FROM tenants WHERE status = 'suspended'"),
                'pending' => (int) $connection->fetchOne("SELECT COUNT(*) FROM tenants WHERE status = 'pending_confirmation'"),
            ],
        ]);
    }
}
