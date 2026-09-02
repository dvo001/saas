<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Event;

use PHPUnit\Framework\TestCase;

final class EventModuleLicenseTest extends TestCase
{
    public function testEventCreationOnlyOffersLicensedModulesAndTemplates(): void
    {
        $root = dirname(__DIR__, 4);
        $licenses = file_get_contents($root.'/src/Core/Application/Billing/LicenseService.php');
        $controller = file_get_contents($root.'/src/Core/Presentation/Web/Controller/Tenant/EventsController.php');
        $events = file_get_contents($root.'/src/Core/Application/Event/EventService.php');
        $template = file_get_contents($root.'/templates/tenant/events/index.html.twig');

        foreach ([$licenses, $controller, $events, $template] as $source) { self::assertIsString($source); }
        self::assertStringContainsString('public function licensedModules(', $licenses);
        self::assertStringContainsString('licenses->licensedModules(', $controller);
        self::assertStringContainsString('licenses->denyUnlessLicensed(', $events);
        self::assertStringContainsString('licenses->isLicensed(', $events);
        self::assertStringContainsString('Abonniertes Modul', $template);
    }
}
