<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Auth;

use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class TenantLoginController extends AbstractController
{
    #[Route('/v/{slug}/login', name: 'tenant_login', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET', 'POST'])]
    public function login(TenantContext $context, AuthenticationUtils $authentication): Response
    {
        return $this->render('auth/login.html.twig', [
            'tenant' => $context->get(),
            'last_username' => $authentication->getLastUsername(),
            'authentication_error' => $authentication->getLastAuthenticationError(),
        ]);
    }

    #[Route('/v/{slug}/logout', name: 'tenant_logout', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['POST'])]
    public function logout(Request $request, TokenStorageInterface $tokens): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('tenant_logout', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
        }

        $slug = $request->attributes->getString('slug');
        $tokens->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('tenant_login', ['slug' => $slug]);
    }
}
