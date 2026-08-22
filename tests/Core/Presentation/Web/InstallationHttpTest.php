<?php

declare(strict_types=1);

namespace App\Tests\Core\Presentation\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstallationHttpTest extends WebTestCase
{
    public function testFreshInstallationRedirectsAnExternalHostToTheWebInstaller(): void
    {
        $client = self::createClient([], ['HTTP_HOST' => 'fresh-install.example.ch', 'HTTPS' => 'on']);
        $lockPath = self::getContainer()->getParameter('app.install_lock_path');
        self::assertIsString($lockPath);
        (new Filesystem())->remove($lockPath);

        try {
            $client->request('GET', '/');

            self::assertResponseRedirects('/install');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Plattform installieren');
        } finally {
            (new Filesystem())->remove($lockPath);
        }
    }
}
