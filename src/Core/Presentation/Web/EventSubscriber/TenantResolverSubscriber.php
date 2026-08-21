<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\EventSubscriber;

use App\Core\Infrastructure\Doctrine\Repository\TenantRepository;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class TenantResolverSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TenantRepository $tenants,
        private TenantContext $context,
        private EntityManagerInterface $entityManager,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Routing runs at priority 32; security runs afterwards.
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $slug = $event->getRequest()->attributes->get('slug');
        if (!is_string($slug) || $slug === '') {
            return;
        }

        $tenant = $this->tenants->findBySlug($slug);
        if ($tenant === null) {
            throw new NotFoundHttpException('Dieser Verein wurde nicht gefunden.');
        }

        $this->context->set($tenant);
        $this->entityManager->getFilters()->enable('tenant')->setParameter('tenant_id', $tenant->getId());
        $event->getRequest()->attributes->set('tenant', $tenant);
    }
}
