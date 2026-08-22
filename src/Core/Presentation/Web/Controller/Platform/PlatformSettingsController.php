<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use App\Core\Infrastructure\Doctrine\Entity\PlatformAdmin;
use App\Core\Infrastructure\Settings\PlatformSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformSettingsController extends AbstractController
{
    #[Route('/platform/einstellungen', name: 'platform_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, PlatformSettings $settings): Response
    {
        $admin = $this->getUser();
        if (!$admin instanceof PlatformAdmin) {
            throw $this->createAccessDeniedException();
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('platform_settings', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            try {
                $name = trim($request->request->getString('platform_name'));
                $operatorEmail = mb_strtolower(trim($request->request->getString('operator_email')));
                $sender = trim($request->request->getString('mail_sender'));
                $dispatcher = $request->request->getInt('dispatcher_minutes');
                $mail = $request->request->getInt('mail_minutes');
                $cleanup = $request->request->getInt('cleanup_hours');
                $vat = $request->request->getInt('vat_basis_points');
                if ($name === '' || filter_var($operatorEmail, FILTER_VALIDATE_EMAIL) === false || filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \DomainException('Bitte Plattformname und gültige E-Mail-Adressen angeben.');
                }
                if ($dispatcher < 1 || $dispatcher > 60 || $mail < 1 || $mail > 60 || $cleanup < 1 || $cleanup > 168) {
                    throw new \DomainException('Die Cronintervalle liegen ausserhalb des erlaubten Bereichs.');
                }
                if ($vat < 0 || $vat > 10000) { throw new \DomainException('Der MwSt.-Satz ist ungültig.'); }
                $ip = $request->getClientIp() ?? '';
                $settings->set('platform.name', $name, $admin, $ip);
                $settings->set('platform.operator', [
                    'name' => trim($request->request->getString('operator_name')),
                    'address' => trim($request->request->getString('operator_address')),
                    'email' => $operatorEmail,
                ], $admin, $ip);
                $settings->set('mail.system_sender', $sender, $admin, $ip);
                $settings->set('cron.intervals', [
                    'dispatcher_minutes' => $dispatcher,
                    'mail_minutes' => $mail,
                    'cleanup_hours' => $cleanup,
                ], $admin, $ip);
                $settings->set('billing.vat_basis_points', $vat, $admin, $ip);
                $settings->set('billing.creditor', [
                    'name' => trim($request->request->getString('creditor_name')),
                    'street' => trim($request->request->getString('creditor_street')),
                    'postal_code' => trim($request->request->getString('creditor_postal_code')),
                    'city' => trim($request->request->getString('creditor_city')),
                    'country_code' => strtoupper(trim($request->request->getString('creditor_country_code'))),
                    'iban' => strtoupper(str_replace(' ', '', $request->request->getString('creditor_iban'))),
                ], $admin, $ip);
                $this->addFlash('success', 'Die Plattform-Einstellungen wurden versioniert gespeichert.');

                return $this->redirectToRoute('platform_settings');
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('platform/admin/settings.html.twig', [
            'platform_name' => $settings->get('platform.name', 'Vereinssport Schweiz'),
            'operator' => $settings->get('platform.operator', ['name' => '', 'address' => '', 'email' => '']),
            'mail_sender' => $settings->get('mail.system_sender', 'noreply@localhost'),
            'cron' => $settings->get('cron.intervals', ['dispatcher_minutes' => 5, 'mail_minutes' => 5, 'cleanup_hours' => 24]),
            'vat_basis_points' => $settings->get('billing.vat_basis_points', 0),
            'creditor' => $settings->get('billing.creditor', ['name' => '', 'street' => '', 'postal_code' => '', 'city' => '', 'country_code' => 'CH', 'iban' => '']),
            'history' => $settings->history(),
            'error' => $error,
        ]);
    }
}
