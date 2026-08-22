<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Application\Billing\AccountingExportService;
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

    #[Route('/platform/rechnungen/export.csv', name: 'platform_accounting_export', methods: ['POST'])]
    public function export(Request $request, AccountingExportService $exports): Response
    {
        if (!$this->isCsrfTokenValid('accounting_export', $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
        $admin = $this->getUser(); if (!$admin instanceof PlatformAdmin) { throw $this->createAccessDeniedException(); }
        try { $export = $exports->create($admin, $request->request->all(), $request->getClientIp() ?? ''); }
        catch (\DomainException $exception) { $this->addFlash('danger', $exception->getMessage()); return $this->redirectToRoute('platform_invoices'); }
        return new Response($export['content'], Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"']);
    }

    #[Route('/platform/rechnungen/{publicId}/bezahlt', name: 'platform_invoice_paid', methods: ['POST'])]
    public function paid(string $publicId, Request $request, SubscriptionBillingService $billing): Response
    {
        if (!$this->isCsrfTokenValid('invoice_paid_'.$publicId, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); }
        $admin = $this->getUser(); if (!$admin instanceof PlatformAdmin) { throw $this->createAccessDeniedException(); }
        try { $billing->recordManualPayment($admin, $publicId, $request->getClientIp() ?? ''); $this->addFlash('success', 'Der Zahlungseingang wurde verbucht.'); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('platform_invoices');
    }
}
