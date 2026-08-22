<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Application\Billing\BillingAdministrationService;
use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformBillingAdministrationController extends AbstractController
{
    #[Route('/platform/billing/kulanz', name: 'platform_billing_administration', methods: ['GET'])]
    public function index(BillingAdministrationService $service): Response { return $this->render('platform/billing/administration.html.twig', ['coupons' => $service->coupons(), 'tenants' => $service->tenants(), 'modules' => $service->modules()]); }
    #[Route('/platform/billing/gutschein', name: 'platform_billing_coupon', methods: ['POST'])]
    public function coupon(Request $request, BillingAdministrationService $service): Response
    {
        $this->csrf($request, 'billing_coupon'); try { $until = $request->request->getString('valid_until'); $code = $service->createCoupon($this->admin(), $request->request->getInt('percentage_basis_points'), $request->request->getString('coupon_type'), $request->request->getString('tenant') ?: null, array_values(array_map('strval', $request->request->all('modules'))), $until === '' ? null : new \DateTimeImmutable($until), $request->getClientIp() ?? ''); $this->addFlash('success', 'Gutschein erstellt (nur jetzt sichtbar): '.$code); } catch (\DomainException|\Exception $e) { $this->addFlash('danger', $e->getMessage()); } return $this->redirectToRoute('platform_billing_administration');
    }
    #[Route('/platform/billing/verlaengern', name: 'platform_billing_extension', methods: ['POST'])]
    public function extension(Request $request, BillingAdministrationService $service): Response
    {
        $this->csrf($request, 'billing_extension'); try { $service->grantExtension($this->admin(), $request->request->getString('tenant'), $request->request->getInt('days'), $request->request->getString('module') ?: null, $request->request->getString('reason'), $request->getClientIp() ?? ''); $this->addFlash('success', 'Kulanzverlängerung gespeichert.'); } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); } return $this->redirectToRoute('platform_billing_administration');
    }
    private function admin(): PlatformAdmin { $admin = $this->getUser(); if (!$admin instanceof PlatformAdmin) { throw $this->createAccessDeniedException(); } return $admin; }
    private function csrf(Request $request, string $id): void { if (!$this->isCsrfTokenValid($id, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException(); } }
}
