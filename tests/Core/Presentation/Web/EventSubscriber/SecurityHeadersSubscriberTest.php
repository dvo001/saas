<?php

declare(strict_types=1);

namespace App\Tests\Core\Presentation\Web\EventSubscriber;

use App\Core\Presentation\Web\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class SecurityHeadersSubscriberTest extends TestCase
{
    public function testItHardensSecureProductionResponsesAndPreventsPrivateCaching(): void
    {
        $request = Request::create('https://club.example.ch/platform');
        $response = new Response('private');
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        (new SecurityHeadersSubscriber('prod'))->onKernelResponse($event);

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString("object-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('upgrade-insecure-requests', (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
}
