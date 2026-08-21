<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Infrastructure\System\SystemStatusService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformSystemStatusController extends AbstractController
{
    #[Route('/platform/systemstatus', name: 'platform_system_status', methods: ['GET'])]
    public function __invoke(SystemStatusService $status): Response
    {
        return $this->render('platform/admin/system_status.html.twig', ['status' => $status->snapshot()]);
    }
}
