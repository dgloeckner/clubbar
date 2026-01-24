<?php

namespace App\Services;

/**
 * TokenService - Terminal API Token Generation and Verification
 *
 * Provides cryptographically secure token generation, bcrypt hashing,
 * and constant-time verification for terminal device authentication.
 *
 * Pattern 012: Terminal API Token Authentication
 *
 * Security Properties:
 * - 256-bit entropy (64 hex characters)
 * - Bcrypt hashing with cost factor 12
 * - Constant-time comparison via password_verify()
 * - Prevents timing attacks on token verification
 */
class TokenService
{
    /**
     * Bcrypt cost factor for token hashing.
     * Cost 12 provides security vs performance balance (~100ms hash time).
     */
    private const BCRYPT_COST = 12;

    /**
     * Entropy size in bytes for token generation.
     * 32 bytes = 256 bits
     */
    private const TOKEN_ENTROPY_BYTES = 32;

    /**
     * Generate a new terminal API token.
     *
     * Produces a 64-character hexadecimal string suitable for transmission
     * to a terminal during pairing. The plaintext token should be displayed
     * once and then discarded; only the hash is stored.
     *
     * @return string 64-character hex token (256-bit entropy)
     */
    public static function generateTerminalToken(): string
    {
        $randomBytes = random_bytes(self::TOKEN_ENTROPY_BYTES);
        return bin2hex($randomBytes);
    }

    /**
     * Hash a plaintext token for secure storage.
     *
     * Uses bcrypt (PASSWORD_BCRYPT) with cost factor 12.
     * Hash is ~60 characters and suitable for VARCHAR(255) storage.
     *
     * @param string $plainToken The plaintext token from generateTerminalToken()
     * @return string Bcrypt hash (~60 chars)
     */
    public static function hashToken(string $plainToken): string
    {
        return password_hash(
            $plainToken,
            PASSWORD_BCRYPT,
            ['cost' => self::BCRYPT_COST]
        );
    }

    /**
     * Verify a plaintext token against a stored hash.
     *
     * Uses password_verify() for constant-time comparison.
     * This prevents timing attacks that could deduce token characters.
     *
     * @param string $plainToken The plaintext token from the client
     * @param string $storedHash The bcrypt hash from database
     * @return bool True if token matches hash
     */
    public static function verifyToken(string $plainToken, string $storedHash): bool
    {
        return password_verify($plainToken, $storedHash);
    }
}
