<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Application\Billing\InvoiceDocumentService;
use App\Core\Application\Billing\SubscriptionBillingService;
use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class TenantBillingController extends AbstractController
{
    #[Route('/v/{slug}/abrechnung', name: 'tenant_billing', methods: ['GET'])]
    public function index(TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->actor();
        return $this->render('tenant/billing.html.twig', ['tenant' => $context->get(), 'billing' => $billing->overview($context->get()), 'catalogue' => $billing->catalogue()]);
    }

    #[Route('/v/{slug}/abrechnung/profil', name: 'tenant_billing_profile', methods: ['POST'])]
    public function profile(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->csrf($request, 'billing_profile');
        try { $pending = $billing->saveProfile($this->actor(), array_map('strval', $request->request->all()), $request->getClientIp() ?? ''); $this->addFlash('success', $pending ? 'Die Adresse wurde gespeichert. Die neue Rechnungs-E-Mail muss noch bestätigt werden.' : 'Die Rechnungsdaten wurden gespeichert.'); }
        catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('tenant_billing', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/abrechnung/buchen', name: 'tenant_billing_book', methods: ['POST'])]
    public function book(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->csrf($request, 'billing_book');
        try { $modules = array_values(array_map('strval', $request->request->all('modules'))); $billing->book($this->actor(), $request->request->getString('main_product'), $modules, $request->request->getString('coupon') ?: null, $request->getClientIp() ?? ''); $this->addFlash('success', 'Das Jahresabo wurde freigeschaltet und die Rechnung erstellt.'); }
        catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('tenant_billing', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/abrechnung/kuendigen', name: 'tenant_billing_cancel', methods: ['POST'])]
    public function cancel(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->csrf($request, 'billing_cancel');
        try { $billing->cancel($this->actor(), $request->getClientIp() ?? ''); $this->addFlash('success', 'Das Abo endet zum bezahlten Laufzeitende.'); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('tenant_billing', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/abrechnung/verlaengerung', name: 'tenant_billing_renewal', methods: ['POST'])]
    public function renewal(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->csrf($request, 'billing_renewal');
        try { $billing->setAutoRenew($this->actor(), $request->request->getBoolean('enabled'), $request->getClientIp() ?? ''); $this->addFlash('success', 'Die automatische Verlängerung wurde geändert.'); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('tenant_billing', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/abrechnung/rechnung/{publicId}.pdf', name: 'tenant_invoice_pdf', methods: ['GET'])]
    public function pdf(string $publicId, TenantContext $context, InvoiceDocumentService $documents): BinaryFileResponse
    {
        $this->actor(); $response = new BinaryFileResponse($documents->pdfPath($context->get(), $publicId)); $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'rechnung.pdf'); return $response;
    }

    private function actor(): TenantUser { $user = $this->getUser(); if (!$user instanceof TenantUser || !in_array($user->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true)) { throw $this->createAccessDeniedException(); } return $user; }
    private function csrf(Request $request, string $id): void { if (!$this->isCsrfTokenValid($id, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); } }
}
