<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Application\Export\TenantExportService;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\SecretCipher;
use App\Core\Infrastructure\Security\Totp;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class TenantExportController extends AbstractController
{
    #[Route('/v/{slug}/datenexport', name: 'tenant_export', methods: ['GET'])]
    public function index(TenantContext $context, TenantExportService $exports): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); }
        try { $jobs = $exports->listFor($user); } catch (\DomainException $exception) { throw $this->createAccessDeniedException($exception->getMessage()); }
        return $this->render('tenant/export/index.html.twig', ['tenant' => $context->get(), 'jobs' => $jobs, 'download_job' => null, 'error' => null]);
    }

    #[Route('/v/{slug}/datenexport/anfordern', name: 'tenant_export_request', methods: ['POST'])]
    public function request(Request $request, TenantContext $context, TenantExportService $exports): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser || !$this->isCsrfTokenValid('tenant_export_request', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        try { $exports->request($user, $request->getClientIp() ?? ''); $this->addFlash('success', 'Der ZIP-Export wurde angefordert und wird im Hintergrund erstellt.'); } catch (\DomainException $exception) { $this->addFlash('danger', $exception->getMessage()); }
        return $this->redirectToRoute('tenant_export', ['slug' => $context->get()->getSlug()]);
    }

    #[Route('/v/{slug}/datenexport/{id}', name: 'tenant_export_download', methods: ['GET', 'POST'])]
    public function download(string $id, Request $request, TenantContext $context, TenantExportService $exports, UserPasswordHasherInterface $passwordHasher, SecretCipher $cipher, Totp $totp): Response
    {
        $user = $this->getUser(); if (!$user instanceof TenantUser) { throw $this->createAccessDeniedException(); }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_export_download_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            $secret = $user->getTotpSecretEncrypted();
            if (!$passwordHasher->isPasswordValid($user, $request->request->getString('password')) || $secret === null || !$totp->verify($cipher->decrypt($secret), $request->request->getString('code'))) {
                $error = 'Passwort oder Zwei-Faktor-Code ist nicht korrekt.';
            } else {
                try { $path = $exports->downloadPath($user, $id, $request->getClientIp() ?? ''); } catch (\DomainException $exception) { $error = $exception->getMessage(); $path = null; }
                if (is_string($path)) { $response = new BinaryFileResponse($path); $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'vereinsdaten-'.$context->get()->getSlug().'.zip'); return $response; }
            }
        }
        try { $jobs = $exports->listFor($user); } catch (\DomainException $exception) { throw $this->createAccessDeniedException($exception->getMessage()); }
        $selected = null; foreach ($jobs as $job) { if ($job['public_id'] === $id) { $selected = $job; break; } }
        if ($selected === null || $selected['status'] !== 'ready') { throw $this->createNotFoundException(); }
        return $this->render('tenant/export/index.html.twig', ['tenant' => $context->get(), 'jobs' => $jobs, 'download_job' => $selected, 'error' => $error]);
    }
}
