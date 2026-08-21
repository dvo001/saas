<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Installation;

use Symfony\Component\HttpFoundation\Request;

final readonly class InstallationInput
{
    public function __construct(
        public DatabaseConfiguration $database,
        public string $platformName,
        public string $baseDomain,
        public string $adminEmail,
        public string $adminPassword,
        public string $mailerDsn,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            new DatabaseConfiguration(
                trim($request->request->getString('db_host')),
                $request->request->getInt('db_port', 3306),
                trim($request->request->getString('db_name')),
                trim($request->request->getString('db_user')),
                $request->request->getString('db_password'),
                trim($request->request->getString('db_server_version', '10.6.0-MariaDB')),
            ),
            trim($request->request->getString('platform_name')),
            trim($request->request->getString('base_domain')),
            mb_strtolower(trim($request->request->getString('admin_email'))),
            $request->request->getString('admin_password'),
            trim($request->request->getString('mailer_dsn', 'null://null')) ?: 'null://null',
        );
    }

    /** @return list<string> */
    public function validate(): array
    {
        $errors = [];
        if (!$this->isValidDatabaseHost($this->database->host)) {
            $errors[] = 'Der Datenbank-Host ist ungültig.';
        }
        if ($this->database->port < 1 || $this->database->port > 65535) {
            $errors[] = 'Der Datenbank-Port ist ungültig.';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $this->database->database)) {
            $errors[] = 'Der Datenbankname ist ungültig.';
        }
        if ($this->database->username === '') {
            $errors[] = 'Datenbank-Benutzer fehlt.';
        }
        if ($this->platformName === '' || mb_strlen($this->platformName) > 120) {
            $errors[] = 'Der Plattformname muss zwischen 1 und 120 Zeichen lang sein.';
        }
        if (filter_var($this->baseDomain, FILTER_VALIDATE_URL) === false || !str_starts_with($this->baseDomain, 'https://')) {
            $errors[] = 'Die Basis-URL muss eine gültige HTTPS-URL sein.';
        }
        if (filter_var($this->adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Die Admin-E-Mail-Adresse ist ungültig.';
        }
        if (strlen($this->adminPassword) < 12) {
            $errors[] = 'Das Admin-Passwort muss mindestens 12 Zeichen lang sein.';
        }
        if (!str_contains($this->mailerDsn, '://')) {
            $errors[] = 'Der Mailer-DSN ist ungültig.';
        }

        return $errors;
    }

    private function isValidDatabaseHost(string $host): bool
    {
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $host) === 1;
    }
}
