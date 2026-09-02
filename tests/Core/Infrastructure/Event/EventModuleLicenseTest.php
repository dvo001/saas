<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Event;

use PHPUnit\Framework\TestCase;

final class EventModuleLicenseTest extends TestCase
{
    public function testEventCreationOnlyOffersLicensedModulesAndTemplates(): void
    {
        $root = dirname(__DIR__, 4);
        $licenses = $this->source($root.'/src/Core/Application/Billing/LicenseService.php');
        $controller = $this->source($root.'/src/Core/Presentation/Web/Controller/Tenant/EventsController.php');
        $events = $this->source($root.'/src/Core/Application/Event/EventService.php');
        $template = $this->source($root.'/templates/tenant/events/index.html.twig');

        self::assertStringContainsString('public function licensedModules(', $licenses);
        self::assertStringContainsString('licenses->licensedModules(', $controller);
        self::assertStringContainsString('licenses->denyUnlessLicensed(', $events);
        self::assertStringContainsString('licenses->isLicensed(', $events);
        self::assertStringContainsString('Abonniertes Modul', $template);
    }

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }
}
