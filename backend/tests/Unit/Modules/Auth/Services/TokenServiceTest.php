<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Services;

use App\Modules\Auth\Services\TokenService;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase
{
    public function test_generateTerminalToken_returns_64_char_hex_string(): void
    {
        $token = TokenService::generateTerminalToken();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_generateTerminalToken_returns_a_different_token_each_call(): void
    {
        $this->assertNotSame(TokenService::generateTerminalToken(), TokenService::generateTerminalToken());
    }

    public function test_hashToken_returns_sha256_hex_digest(): void
    {
        $hash = TokenService::hashToken('plain-token');

        $this->assertSame(hash('sha256', 'plain-token'), $hash);
        $this->assertSame(64, strlen($hash));
    }

    public function test_hashToken_is_deterministic(): void
    {
        $this->assertSame(TokenService::hashToken('same-input'), TokenService::hashToken('same-input'));
    }

    public function test_verifyToken_accepts_matching_sha256_hash(): void
    {
        $plain = TokenService::generateTerminalToken();
        $hash = TokenService::hashToken($plain);

        $this->assertTrue(TokenService::verifyToken($plain, $hash));
    }

    public function test_verifyToken_rejects_wrong_token_against_sha256_hash(): void
    {
        $hash = TokenService::hashToken('correct-token');

        $this->assertFalse(TokenService::verifyToken('wrong-token', $hash));
    }

    public function test_verifyToken_accepts_matching_legacy_bcrypt_hash(): void
    {
        // Legacy format: bcrypt hash of the plain token, from before the SHA256 migration.
        $bcryptHash = password_hash('legacy-token', PASSWORD_BCRYPT);

        $this->assertTrue(TokenService::verifyToken('legacy-token', $bcryptHash));
    }

    public function test_verifyToken_rejects_wrong_token_against_legacy_bcrypt_hash(): void
    {
        $bcryptHash = password_hash('legacy-token', PASSWORD_BCRYPT);

        $this->assertFalse(TokenService::verifyToken('wrong-token', $bcryptHash));
    }

    public function test_verifyToken_rejects_empty_stored_hash(): void
    {
        $this->assertFalse(TokenService::verifyToken('any-token', ''));
    }

    public function test_verifyToken_treats_64_char_hex_looking_string_starting_with_bcrypt_prefix_as_legacy(): void
    {
        // A stored hash of exactly 64 chars that happens to start with the bcrypt
        // prefix must still go through password_verify(), not hash_equals().
        $bcryptHash = '$2y$' . str_repeat('a', 60);
        $this->assertSame(64, strlen($bcryptHash));

        $this->assertFalse(TokenService::verifyToken('any-token', $bcryptHash));
    }
}
