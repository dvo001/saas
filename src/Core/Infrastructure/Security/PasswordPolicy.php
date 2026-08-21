<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Security;

final class PasswordPolicy
{
    /** @var list<string> */
    private const COMMON = ['password', 'passwort', '123456789012', 'qwertzuiop', 'verein123456', 'admin123456'];

    /** @return list<string> */
    public function violations(string $password): array
    {
        $violations = [];
        if (mb_strlen($password) < 12) {
            $violations[] = 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        }

        $normalised = mb_strtolower(trim($password));
        if (in_array($normalised, self::COMMON, true) || preg_match('/^(.)\1+$/u', $password) === 1) {
            $violations[] = 'Dieses Passwort ist zu leicht zu erraten.';
        }

        return $violations;
    }
}
