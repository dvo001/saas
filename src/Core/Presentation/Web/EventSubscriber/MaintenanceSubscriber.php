<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\EventSubscriber;

use App\Core\Infrastructure\Maintenance\MaintenanceService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

final readonly class MaintenanceSubscriber implements EventSubscriberInterface
{
    public function __construct(private MaintenanceService $maintenance, private Environment $twig) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 14]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/v/')) {
            return;
        }
        $active = $this->maintenance->active();
        if ($active === null) {
            return;
        }

        $end = new \DateTimeImmutable((string) $active['expected_end_at'], new \DateTimeZone('UTC'));
        $response = new Response($this->twig->render('maintenance.html.twig', ['maintenance' => $active]), Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Retry-After', (string) max(60, $end->getTimestamp() - time()));
        $response->headers->set('Cache-Control', 'no-store');
        $event->setResponse($response);
    }
}
