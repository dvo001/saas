<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Installation;

final readonly class DatabaseConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $serverVersion = '10.6.0-MariaDB',
    ) {
    }

    public function pdoDsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database);
    }

    public function databaseUrl(): string
    {
        return sprintf(
            'mysql://%s:%s@%s:%d/%s?serverVersion=%s&charset=utf8mb4',
            rawurlencode($this->username),
            rawurlencode($this->password),
            $this->hostForUrl(),
            $this->port,
            rawurlencode($this->database),
            rawurlencode($this->serverVersion),
        );
    }

    private function hostForUrl(): string
    {
        return str_contains($this->host, ':') ? '[' . trim($this->host, '[]') . ']' : $this->host;
    }
}
