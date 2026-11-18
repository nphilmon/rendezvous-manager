<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Security\PasswordHasher;
use Tests\TestCase;

final class PasswordTest extends TestCase
{
    public function testStrength(): void
    {
        $this->assertTrue(PasswordHasher::validateStrength('Abcdefghij1k') === []);
        $this->assertTrue(count(PasswordHasher::validateStrength('court1A')) > 0);
        $this->assertTrue(count(PasswordHasher::validateStrength('alllowercase123')) > 0);
        $this->assertTrue(count(PasswordHasher::validateStrength('Alice-Password1', 12, 'alice')) > 0);
    }

    public function testHashVerifyRehash(): void
    {
        $h = PasswordHasher::hash('Abcdefghij1k');
        $this->assertTrue(PasswordHasher::verify('Abcdefghij1k', $h) && !PasswordHasher::verify('x', $h));
        $this->assertTrue(!PasswordHasher::needsRehash($h));
        $this->assertTrue(PasswordHasher::needsRehash(password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])) || !defined('PASSWORD_ARGON2ID'));
    }
}
