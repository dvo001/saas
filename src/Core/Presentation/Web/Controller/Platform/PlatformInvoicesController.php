<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Application\Billing\SubscriptionBillingService;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformInvoicesController extends AbstractController
{
    #[Route('/platform/rechnungen', name: 'platform_invoices', methods: ['GET'])]
    public function index(SubscriptionBillingService $billing): Response { return $this->render('platform/billing/invoices.html.twig', ['invoices' => $billing->platformInvoices()]); }

    #[Route('/platform/rechnungen/{publicId}/bezahlt', name: 'platform_invoice_paid', methods: ['POST'])]
    public function paid(string $publicId, Request $request, SubscriptionBillingService $billing): Response
    {
        if (!$this->isCsrfTokenValid('invoice_paid_'.$publicId, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
        $admin = $this->getUser(); if (!$admin instanceof PlatformAdmin) { throw $this->createAccessDeniedException(); }
        try { $billing->recordManualPayment($admin, $publicId, $request->getClientIp() ?? ''); $this->addFlash('success', 'Der Zahlungseingang wurde verbucht.'); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('platform_invoices');
    }
}
