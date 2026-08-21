<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Auth;

use App\Core\Application\Registration\UserAdministrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InvitationController extends AbstractController
{
    #[Route('/einladung/{token}', name: 'invitation_accept', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function __invoke(string $token, Request $request, UserAdministrationService $administration): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('invitation_'.$token, $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            try {
                $user = $administration->acceptInvitation($token, $request->request->getString('display_name'), $request->request->getString('password'), $request->getClientIp() ?? '');

                return $this->redirectToRoute('tenant_login', ['slug' => $user->getTenant()->getSlug(), 'invited' => 1]);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('auth/invitation.html.twig', ['token' => $token, 'error' => $error]);
    }
}
