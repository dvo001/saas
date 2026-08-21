<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

final readonly class SecretCipher
{
    private string $key;

    public function __construct(string $appSecret)
    {
        $this->key = hash('sha256', $appSecret, true);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Could not encrypt the security secret.');
        }

        return base64_encode($nonce.$tag.$ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) < 29) {
            throw new \RuntimeException('The security secret is invalid.');
        }

        $plaintext = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
        if ($plaintext === false) {
            throw new \RuntimeException('Could not decrypt the security secret.');
        }

        return $plaintext;
    }
}
