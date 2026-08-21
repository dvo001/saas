<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\EventSubscriber;

use App\Core\Infrastructure\Installation\InstallationState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class InstallationGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(private InstallationState $state)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 120]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->state->isInstalled()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ($path === '/install' || str_starts_with($path, '/_')) {
            return;
        }

        if ($this->state->isInstallerAvailable()) {
            $event->setResponse(new RedirectResponse('/install'));

            return;
        }

        $event->setResponse(new Response(
            'Die Plattform ist nicht installiert und der Installer ist serverseitig gesperrt.',
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        ));
    }
}
