<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Application\Billing\InvoiceDocumentService;
use App\Core\Application\Billing\SubscriptionBillingService;
use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\SensitiveActionAuthenticator;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class TenantBillingController extends AbstractController
{
    public function __construct(private readonly SensitiveActionAuthenticator $sensitiveActions) {}

    #[Route('/v/{slug}/abrechnung', name: 'tenant_billing', methods: ['GET'])]
    public function index(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $actor = $this->actor();
        $overview = $billing->overview($context->get());
        $catalogue = $billing->catalogue();
        $licensedModuleCodes = array_column($overview['modules'], 'code');
        $availableAddons = array_values(array_filter($catalogue['modules'], static fn (array $module): bool => !in_array($module['module_code'], $licensedModuleCodes, true)));

        return $this->render('tenant/billing.html.twig', ['tenant' => $context->get(), 'billing' => $overview, 'catalogue' => $catalogue, 'available_addons' => $availableAddons, 'billing_reauthenticated' => $this->sensitiveActions->isRecent($actor, $request->getSession())]);
    }

    #[Route('/v/{slug}/abrechnung/bestaetigen', name: 'tenant_billing_reauthenticate', methods: ['POST'])]
    public function reauthenticate(Request $request, TenantContext $context): Response
    {
        $this->csrf($request, 'billing_reauthenticate');
        if ($this->sensitiveActions->authenticate($this->actor(), $request->request->getString('password'), $request->request->getString('code'), $request->getSession())) {
            $this->addFlash('success', 'Die Identität ist für zehn Minuten bestätigt.');
        } else {
            $this->addFlash('danger', 'Passwort oder Zwei-Faktor-Code ist nicht korrekt.');
        }

        return $this->redirectToRoute('tenant_billing', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/abrechnung/profil', name: 'tenant_billing_profile', methods: ['POST'])]
    public function profile(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->csrf($request, 'billing_profile');
        try { $actor = $this->actor(); $this->sensitiveActions->requireRecent($actor, $request->getSession()); $pending = $billing->saveProfile($actor, array_map('strval', $request->request->all()), $request->getClientIp() ?? ''); $this->addFlash('success', $pending ? 'Die Adresse wurde gespeichert. Die neue Rechnungs-E-Mail muss noch bestätigt werden.' : 'Die Rechnungsdaten wurden gespeichert.'); }
        catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('tenant_billing', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/abrechnung/buchen', name: 'tenant_billing_book', methods: ['POST'])]
    public function book(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->csrf($request, 'billing_book');
        try { $actor = $this->actor(); $this->sensitiveActions->requireRecent($actor, $request->getSession()); $modules = array_values(array_map('strval', $request->request->all('modules'))); $billing->book($actor, $request->request->getString('main_product'), $modules, $request->request->getString('coupon') ?: null, $request->getClientIp() ?? ''); $this->addFlash('success', 'Das Jahresabo wurde freigeschaltet und die Rechnung erstellt.'); }
        catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('tenant_billing', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/abrechnung/zusatzmodul', name: 'tenant_billing_addon', methods: ['POST'])]
    public function addOn(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->csrf($request, 'billing_addon');
        try {
            $actor = $this->actor();
            $this->sensitiveActions->requireRecent($actor, $request->getSession());
            $billing->addOn($actor, $request->request->getString('module_product'), $request->getClientIp() ?? '');
            $this->addFlash('success', 'Das Zusatzmodul wurde bis zum Laufzeitende freigeschaltet und die Rechnung erstellt.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('tenant_billing', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/abrechnung/kuendigen', name: 'tenant_billing_cancel', methods: ['POST'])]
    public function cancel(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->csrf($request, 'billing_cancel');
        try { $actor = $this->actor(); $this->sensitiveActions->requireRecent($actor, $request->getSession()); $billing->cancel($actor, $request->getClientIp() ?? ''); $this->addFlash('success', 'Das Abo endet zum bezahlten Laufzeitende.'); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('tenant_billing', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/abrechnung/verlaengerung', name: 'tenant_billing_renewal', methods: ['POST'])]
    public function renewal(Request $request, TenantContext $context, SubscriptionBillingService $billing): Response
    {
        $this->csrf($request, 'billing_renewal');
        try { $actor = $this->actor(); $this->sensitiveActions->requireRecent($actor, $request->getSession()); $billing->setAutoRenew($actor, $request->request->getBoolean('enabled'), $request->getClientIp() ?? ''); $this->addFlash('success', 'Die automatische Verlängerung wurde geändert.'); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
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
