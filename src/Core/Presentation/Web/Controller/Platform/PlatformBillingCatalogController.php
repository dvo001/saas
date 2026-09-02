<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Application\Billing\BillingCatalogService;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformBillingCatalogController extends AbstractController
{
    #[Route('/platform/produkte', name: 'platform_billing_products', methods: ['GET', 'POST'])]
    public function index(Request $request, BillingCatalogService $catalog): Response
    {
        $admin = $this->admin();
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('billing_product_create', $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
            try {
                $catalog->createProduct($admin, $request->request->getString('product_key'), $request->request->getString('name'), $request->request->getString('product_type'), $request->request->getString('module') ?: null, $request->getClientIp() ?? '');
                $this->addFlash('success', 'Das Produkt wurde ohne fest codierten Preis angelegt.');

                return $this->redirectToRoute('platform_billing_products');
            } catch (\DomainException $exception) { $error = $exception->getMessage(); }
        }

        $includeInactive = $request->query->getBoolean('mit_deaktivierten');

        return $this->render('platform/billing/products.html.twig', ['products' => $catalog->products($includeInactive), 'modules' => $catalog->modules(), 'include_inactive' => $includeInactive, 'error' => $error]);
    }

    #[Route('/platform/produkte/{publicId}/preis', name: 'platform_billing_price', methods: ['POST'])]
    public function price(string $publicId, Request $request, BillingCatalogService $catalog): Response
    {
        if (!$this->isCsrfTokenValid('billing_price_'.$publicId, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
        try {
            $raw = $request->request->getString('valid_from');
            $validFrom = $raw === '' ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, new \DateTimeZone('Europe/Zurich'));
            if (!$validFrom instanceof \DateTimeImmutable) { throw new \DomainException('Bitte ein gültiges Gültigkeitsdatum angeben.'); }
            $catalog->setPrice($this->admin(), $publicId, $request->request->getString('amount'), $validFrom, $request->getClientIp() ?? '');
            $this->addFlash('success', 'Die neue Preisversion wurde gespeichert.');
        } catch (\DomainException $exception) { $this->addFlash('danger', $exception->getMessage()); }

        return $this->redirectToRoute('platform_billing_products', $this->filterParameters($request));
    }

    #[Route('/platform/produkte/{publicId}/{action}', name: 'platform_billing_product_action', requirements: ['action' => 'aktivieren|deaktivieren'], methods: ['POST'])]
    public function action(string $publicId, string $action, Request $request, BillingCatalogService $catalog): Response
    {
        if (!$this->isCsrfTokenValid('billing_product_'.$publicId, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
        try {
            $catalog->setProductActive($this->admin(), $publicId, $action === 'aktivieren', $request->getClientIp() ?? '');
            $this->addFlash('success', 'Der Produktstatus wurde geändert. Laufende Abos behalten ihre Preisversion.');
        } catch (\DomainException $exception) { $this->addFlash('danger', $exception->getMessage()); }

        return $this->redirectToRoute('platform_billing_products', $this->filterParameters($request));
    }

    #[Route('/platform/produkte/{publicId}/loeschen', name: 'platform_billing_product_delete', methods: ['POST'])]
    public function delete(string $publicId, Request $request, BillingCatalogService $catalog): Response
    {
        if (!$this->isCsrfTokenValid('billing_product_delete_'.$publicId, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
        try {
            $catalog->deleteProduct($this->admin(), $publicId, $request->getClientIp() ?? '');
            $this->addFlash('success', 'Das unbenutzte Produkt und seine Preisversionen wurden gelöscht.');
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('platform_billing_products', $this->filterParameters($request));
    }

    #[Route('/platform/module/{code}/{action}', name: 'platform_billing_module_action', requirements: ['action' => 'aktivieren|deaktivieren'], methods: ['POST'])]
    public function moduleAction(string $code, string $action, Request $request, BillingCatalogService $catalog): Response
    {
        if (!$this->isCsrfTokenValid('billing_module_'.$code, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
        try { $catalog->setModuleActive($this->admin(), $code, $action === 'aktivieren', $request->getClientIp() ?? ''); $this->addFlash('success', 'Der plattformweite Modulstatus wurde geändert.'); } catch (\DomainException $exception) { $this->addFlash('danger', $exception->getMessage()); }
        return $this->redirectToRoute('platform_billing_products');
    }

    private function admin(): PlatformAdmin
    {
        $admin = $this->getUser();
        if (!$admin instanceof PlatformAdmin) { throw $this->createAccessDeniedException(); }

        return $admin;
    }

    /** @return array<string, int> */
    private function filterParameters(Request $request): array
    {
        return $request->query->getBoolean('mit_deaktivierten') ? ['mit_deaktivierten' => 1] : [];
    }
}
