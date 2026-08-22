<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\EventSubscriber;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class PlatformSessionSubscriber implements EventSubscriberInterface
{
    private const CHALLENGE_ROUTES = ['platform_2fa_setup', 'platform_2fa_verify', 'platform_logout'];
    public function __construct(private TokenStorageInterface $tokens, private UrlGeneratorInterface $urls) {}
    public static function getSubscribedEvents(): array { return [KernelEvents::REQUEST => ['onKernelRequest', -10]]; }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/platform') || !$request->hasSession()) { return; }
        $admin = $this->tokens->getToken()?->getUser();
        if (!$admin instanceof PlatformAdmin) { return; }
        $session = $request->getSession();
        $now = time();
        $startedAt = (int) $session->get('platform_auth_started_at', $now);
        if ($now - (int) $session->get('platform_last_activity_at', $now) > 7200 || $now - $startedAt > 28800) {
            $this->tokens->setToken(null);
            $session->invalidate();
            $event->setResponse(new RedirectResponse($this->urls->generate('platform_login', ['expired' => 1])));

            return;
        }
        $session->set('platform_auth_started_at', $startedAt);
        $session->set('platform_last_activity_at', $now);
        if ($session->get('platform_two_factor_passed') !== true && !in_array($request->attributes->get('_route'), self::CHALLENGE_ROUTES, true)) {
            $event->setResponse(new RedirectResponse($this->urls->generate($admin->hasTwoFactor() ? 'platform_2fa_verify' : 'platform_2fa_setup')));
        }
    }
}
