<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

final readonly class ErrorReferenceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private Environment $twig,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', -64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if ($this->debug || !$event->isMainRequest()) {
            return;
        }

        $throwable = $event->getThrowable();
        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
        $reference = $status >= 500 ? Uuid::v7()->toRfc4122() : null;

        if ($reference !== null) {
            $this->logger->error('Unhandled application error.', [
                'error_reference' => $reference,
                'exception' => $throwable,
            ]);
        }

        $event->setResponse(new Response(
            $this->twig->render('error/error.html.twig', [
                'status' => $status,
                'reference' => $reference,
            ]),
            $status,
        ));
    }
}
