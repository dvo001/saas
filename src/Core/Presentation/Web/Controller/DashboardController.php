<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller;

use App\Core\Infrastructure\Settings\PlatformSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(PlatformSettings $settings): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'platform_name' => $settings->get('platform.name', 'Vereinssport Schweiz'),
        ]);
    }
}
