<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

class TokenService
{
    private const BCRYPT_COST = 12;
    private const TOKEN_ENTROPY_BYTES = 32;

    public static function generateTerminalToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_ENTROPY_BYTES));
    }

    public static function hashToken(string $plainToken): string
    {
        return password_hash($plainToken, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    public static function verifyToken(string $plainToken, string $storedHash): bool
    {
        return password_verify($plainToken, $storedHash);
    }
}
