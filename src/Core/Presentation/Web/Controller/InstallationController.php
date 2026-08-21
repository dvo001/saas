<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller;

use App\Core\Infrastructure\Installation\InstallationInput;
use App\Core\Infrastructure\Installation\InstallationService;
use App\Core\Infrastructure\Installation\InstallationState;
use App\Core\Infrastructure\Installation\SystemRequirements;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class InstallationController extends AbstractController
{
    #[Route('/install', name: 'app_install', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        InstallationState $state,
        SystemRequirements $requirements,
        InstallationService $installer,
        LoggerInterface $logger,
        string $projectDirectory,
    ): Response {
        if (!$state->isInstallerAvailable()) {
            throw $this->createNotFoundException();
        }

        $checks = $requirements->check($projectDirectory);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('installation', $request->request->getString('_token'))) {
                $errors[] = 'Die Formularsitzung ist abgelaufen. Bitte erneut versuchen.';
            }
            if (!$requirements->allMet($checks)) {
                $errors[] = 'Nicht alle Systemvoraussetzungen sind erfüllt.';
            }

            $input = InstallationInput::fromRequest($request);
            $errors = [...$errors, ...$input->validate()];

            if ($errors === []) {
                try {
                    $installer->install($input);

                    return $this->render('installation/success.html.twig', [
                        'platform_name' => $input->platformName,
                    ]);
                } catch (\Throwable $exception) {
                    $reference = Uuid::v7()->toRfc4122();
                    $logger->error('Installation failed.', [
                        'error_reference' => $reference,
                        'exception_class' => $exception::class,
                        'exception_code' => $exception->getCode(),
                    ]);
                    $errors[] = 'Die Installation konnte nicht abgeschlossen werden. Fehlerreferenz: ' . $reference;
                }
            }
        }

        return $this->render('installation/index.html.twig', [
            'checks' => $checks,
            'requirements_met' => $requirements->allMet($checks),
            'errors' => $errors,
        ]);
    }
}
