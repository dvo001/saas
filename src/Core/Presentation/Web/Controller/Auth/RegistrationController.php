<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Auth;

use App\Core\Application\Registration\RegistrationService;
use App\Core\Domain\Tenant\TrialModule;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/registrieren', name: 'registration', methods: ['GET', 'POST'])]
    public function register(Request $request, RegistrationService $registrations): Response
    {
        $session = $request->getSession();
        /** @var array<string, string> $data */
        $data = $session->get('registration_wizard', []);
        $step = max(1, min(3, (int) $session->get('registration_step', 1)));
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('registration_step_'.$step, $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            if ($request->request->has('back')) {
                $step = max(1, $step - 1);
                $session->set('registration_step', $step);
            } elseif ($step === 1) {
                $data['club_name'] = trim($request->request->getString('club_name'));
                $data['slug'] = mb_strtolower(trim($request->request->getString('slug')));
                $data['module'] = $request->request->getString('module');
                if ($data['club_name'] === '' || TrialModule::tryFrom($data['module']) === null || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $data['slug']) !== 1) {
                    $error = 'Bitte alle Angaben zum Verein korrekt ausfüllen.';
                } else {
                    $step = 2;
                }
            } elseif ($step === 2) {
                $data['display_name'] = trim($request->request->getString('display_name'));
                $data['email'] = mb_strtolower(trim($request->request->getString('email')));
                if ($data['display_name'] === '' || filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                    $error = 'Bitte Name und E-Mail-Adresse korrekt eingeben.';
                } else {
                    $step = 3;
                }
            } else {
                $data['password'] = $request->request->getString('password');
                if (!$request->request->getBoolean('legal_accepted')) {
                    $error = 'Bitte Nutzungsbedingungen und Datenschutzerklärung akzeptieren.';
                } else {
                    try {
                        $registrationData = [
                            'club_name' => $data['club_name'] ?? '',
                            'slug' => $data['slug'] ?? '',
                            'module' => $data['module'] ?? '',
                            'display_name' => $data['display_name'] ?? '',
                            'email' => $data['email'] ?? '',
                            'password' => $data['password'],
                        ];
                        $tenant = $registrations->register($registrationData, $request->getClientIp() ?? '');
                        $session->remove('registration_wizard');
                        $session->remove('registration_step');

                        return $this->render('registration/pending.html.twig', ['tenant' => $tenant]);
                    } catch (\DomainException $exception) {
                        $error = $exception->getMessage();
                    }
                }
            }

            unset($data['password']);
            $session->set('registration_wizard', $data);
            $session->set('registration_step', $step);
        }

        return $this->render('registration/wizard.html.twig', [
            'step' => $step,
            'data' => $data,
            'modules' => TrialModule::cases(),
            'legal_version' => RegistrationService::LEGAL_VERSION,
            'error' => $error,
        ]);
    }

    #[Route('/registrierung/bestaetigen/{token}', name: 'registration_confirm', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function confirm(string $token, Request $request, RegistrationService $registrations): Response
    {
        if (!$request->isMethod('POST')) { return $this->render('auth/token_confirmation.html.twig', ['title' => 'Registrierung bestätigen', 'message' => 'Aktiviere den Vereinsaccount und starte die Testphase.', 'button' => 'Registrierung aktivieren', 'csrf_id' => 'registration_confirm_'.$token]); }
        if (!$this->isCsrfTokenValid('registration_confirm_'.$token, $request->request->getString('_csrf_token'))) { throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.'); }
        try {
            $tenant = $registrations->confirm($token, $request->getClientIp() ?? '');

            return $this->render('registration/confirmed.html.twig', ['tenant' => $tenant]);
        } catch (\DomainException $exception) {
            return $this->render('registration/confirmation_error.html.twig', ['message' => $exception->getMessage()], new Response(status: Response::HTTP_BAD_REQUEST));
        }
    }

    #[Route('/registrierung/bestaetigung-erneut', name: 'registration_resend', methods: ['GET', 'POST'])]
    public function resend(Request $request, RegistrationService $registrations): Response
    {
        $sent = false;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('registration_resend', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Sicherheitstoken.');
            }
            $registrations->resendConfirmation($request->request->getString('email'), $request->request->getString('slug'));
            $sent = true;
        }

        return $this->render('registration/resend.html.twig', ['sent' => $sent]);
    }
}
