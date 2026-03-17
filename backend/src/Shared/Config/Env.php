<?php

declare(strict_types=1);

namespace App\Shared\Config;

class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!file_exists($file)) {
            throw new \RuntimeException(".env not found: {$file}");
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            if (!str_contains($line, '=')) continue;

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");

            self::$vars[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }

        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }

        $fromSystem = getenv($key);
        if ($fromSystem !== false) {
            return $fromSystem;
        }

        if ($default !== null) {
            return $default;
        }

        throw new \RuntimeException("Missing env var: {$key}");
    }

    public static function require(array $keys): void
    {
        $missing = array_filter($keys, fn($k) =>
            !isset(self::$vars[$k]) && !isset($_ENV[$k]) && !getenv($k)
        );
        if ($missing) {
            throw new \RuntimeException('Missing required env vars: ' . implode(', ', $missing));
        }
    }

    /**
     * Reset loaded vars (useful for testing).
     */
    public static function reset(): void
    {
        self::$vars = [];
    }
}
