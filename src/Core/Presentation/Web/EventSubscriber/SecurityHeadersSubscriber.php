<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly string $environment) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $policy = "default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
        if ($this->environment === 'prod') { $policy .= '; upgrade-insecure-requests'; }
        $headers->set('Content-Security-Policy', $policy);
        if ($this->environment === 'prod' && $request->isSecure()) { $headers->set('Strict-Transport-Security', 'max-age=31536000'); }
        if ($request->hasSession() || str_starts_with($request->getPathInfo(), '/platform') || str_starts_with($request->getPathInfo(), '/v/')) {
            $response->setPrivate(); $response->setMaxAge(0); $headers->addCacheControlDirective('no-store');
        }
    }
}
