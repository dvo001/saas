<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\EventSubscriber;

use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class TenantSessionSubscriber implements EventSubscriberInterface
{
    private const TWO_FACTOR_ROUTES = ['tenant_2fa_setup', 'tenant_2fa_verify', 'tenant_logout'];

    public function __construct(
        private TokenStorageInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', -10]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->hasSession()) {
            return;
        }

        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof TenantUser) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->getSession();
        $now = time();
        $startedAt = (int) $session->get('tenant_auth_started_at', $now);
        $lastActivity = (int) $session->get('last_activity_at', $now);
        $idleLimit = $user->getTenantRole()->sensitiveSession() ? 7200 : 28800;
        $absoluteLimit = $user->getTenantRole()->sensitiveSession() ? 28800 : 86400;
        if ($now - $lastActivity > $idleLimit || $now - $startedAt > $absoluteLimit) {
            $this->tokens->setToken(null);
            $session->invalidate();
            $event->setResponse(new RedirectResponse($this->urls->generate('tenant_login', ['slug' => $user->getTenant()->getSlug(), 'expired' => 1])));

            return;
        }

        $session->set('tenant_auth_started_at', $startedAt);
        $session->set('last_activity_at', $now);
        $needsTwoFactor = $user->requiresTwoFactor() || $user->hasTwoFactor();
        $route = $request->attributes->get('_route');
        if ($needsTwoFactor && $session->get('two_factor_passed') !== true && !in_array($route, self::TWO_FACTOR_ROUTES, true)) {
            $target = $user->hasTwoFactor() ? 'tenant_2fa_verify' : 'tenant_2fa_setup';
            $event->setResponse(new RedirectResponse($this->urls->generate($target, ['slug' => $user->getTenant()->getSlug()])));
        }
    }
}
