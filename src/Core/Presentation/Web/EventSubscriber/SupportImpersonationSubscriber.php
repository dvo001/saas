<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\EventSubscriber;

use App\Core\Infrastructure\Security\SupportTenantUser;
use App\Core\Infrastructure\Support\SupportSessionService;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final readonly class SupportImpersonationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TenantContext $tenantContext,
        private SupportSessionService $supportSessions,
        private TokenStorageInterface $tokens,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Runs after the tenant firewall restored its token and before access control.
        return [KernelEvents::REQUEST => ['onKernelRequest', -8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->hasSession()) {
            return;
        }

        $tenant = $this->tenantContext->getOrNull();
        if ($tenant === null) {
            return;
        }

        $session = $event->getRequest()->getSession();
        $rawToken = (string) $session->get(SupportSessionService::SESSION_KEY, '');
        $active = $this->supportSessions->resolve($rawToken, $tenant);
        if ($active === null) {
            $session->remove(SupportSessionService::SESSION_KEY);

            return;
        }

        $user = new SupportTenantUser($tenant, $active->platformAdmin);
        $this->tokens->setToken(new PostAuthenticationToken($user, 'tenant', $user->getRoles()));
        $event->getRequest()->attributes->set('support_session', $active);
    }
}
