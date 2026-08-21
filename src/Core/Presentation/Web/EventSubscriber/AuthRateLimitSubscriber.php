<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class AuthRateLimitSubscriber implements EventSubscriberInterface
{
    /** @var array<string, string> */
    private const ROUTE_LIMITERS = [
        'password_forgot' => 'recovery',
        'owner_unlock_request' => 'recovery',
        'tenant_users' => 'invitation',
        'invitation_accept' => 'invitation',
        'tenant_2fa_setup' => 'two_factor',
        'tenant_2fa_verify' => 'two_factor',
        'registration' => 'registration',
        'registration_resend' => 'registration',
    ];

    public function __construct(
        private RateLimiterFactory $recoveryLimiter,
        private RateLimiterFactory $invitationLimiter,
        private RateLimiterFactory $twoFactorLimiter,
        private RateLimiterFactory $registrationLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->isMethod('POST')) {
            return;
        }
        $route = $request->attributes->get('_route');
        if (!is_string($route) || !isset(self::ROUTE_LIMITERS[$route])) {
            return;
        }

        $factory = match (self::ROUTE_LIMITERS[$route]) {
            'recovery' => $this->recoveryLimiter,
            'invitation' => $this->invitationLimiter,
            'two_factor' => $this->twoFactorLimiter,
            'registration' => $this->registrationLimiter,
        };
        $key = hash('sha256', ($request->getClientIp() ?? 'unknown').'|'.$route.'|'.$request->attributes->getString('slug'));
        $limit = $factory->create($key)->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Zu viele Versuche. Bitte später erneut versuchen.');
        }
    }
}
