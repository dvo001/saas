<?php

declare(strict_types=1);

namespace App\Tests\Core\Infrastructure\Security;

use App\Core\Infrastructure\Security\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    #[DataProvider('invalidPasswords')]
    public function testRejectsShortAndCommonPasswords(string $password): void
    {
        self::assertNotSame([], (new PasswordPolicy())->violations($password));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPasswords(): iterable
    {
        yield 'short' => ['ZuKurz123'];
        yield 'common' => ['password'];
        yield 'repeated character' => ['aaaaaaaaaaaa'];
    }

    public function testAcceptsLongNonCommonPassword(): void
    {
        self::assertSame([], (new PasswordPolicy())->violations('Berglauf!2026-Sicher'));
    }
}
