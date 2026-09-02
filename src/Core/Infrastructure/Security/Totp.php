<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $position = (int) bindec(str_pad($chunk, 5, '0'));
            $secret .= self::ALPHABET[$position];
        }

        return $secret;
    }

    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$account);

        return sprintf('otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30', $label, $secret, rawurlencode($issuer));
    }

    public function qrCodeDataUri(string $provisioningUri): string
    {
        return (new Builder(
            writer: new SvgWriter(),
            writerOptions: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true],
            data: $provisioningUri,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 280,
            margin: 12,
        ))->build()->getDataUri();
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -1; $offset <= 1; ++$offset) {
            if (hash_equals($this->code($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    private function code(string $secret, int $counter): string
    {
        $binary = $this->decodeBase32($secret);
        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $hash = hash_hmac('sha1', pack('N2', $high, $low), $binary, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4));
        $number = (($value[1] ?? 0) & 0x7fffffff) % 1000000;

        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    private function decodeBase32(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new \InvalidArgumentException('Invalid Base32 secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $byte = (int) bindec($chunk);
                if ($byte < 0 || $byte > 255) {
                    throw new \InvalidArgumentException('Invalid Base32 byte.');
                }
                $decoded .= chr($byte);
            }
        }

        return $decoded;
    }
}
