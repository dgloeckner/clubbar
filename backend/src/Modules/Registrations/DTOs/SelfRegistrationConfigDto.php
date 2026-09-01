<?php

declare(strict_types=1);

namespace App\Modules\Registrations\DTOs;

/**
 * The club's self-registration settings, as the rest of the module sees them
 * (migration 059, ADR-0052 decisions 1 and 2).
 *
 * `fromRow()` takes a nullable row and answers the **shipped state** for a
 * missing one rather than throwing. That is not defensive habit: an
 * installation whose config row does not exist yet — one predating the
 * migration, or one an operator emptied — must read as *off with no secret*.
 * Any other reading of an absent row, an exception included, risks a surface
 * that collects personal data because nobody had said it should not.
 */
final readonly class SelfRegistrationConfigDto
{
    /** What a fresh installation gets, and what a missing row means. */
    public const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        public bool $enabled,
        public ?string $disabledReason,
        public ?string $secretHash,
        public ?string $secretCipher,
        public ?string $secretRotatedAt,
        public int $retentionDays,
    ) {}

    /** @param array<string, mixed>|null $row */
    public static function fromRow(?array $row): self
    {
        if ($row === null) {
            return new self(
                enabled: false,
                disabledReason: null,
                secretHash: null,
                secretCipher: null,
                secretRotatedAt: null,
                retentionDays: self::DEFAULT_RETENTION_DAYS,
            );
        }

        return new self(
            enabled: (bool) ($row['enabled'] ?? false),
            disabledReason: self::nullableString($row['disabled_reason'] ?? null),
            secretHash: self::nullableString($row['secret_hash'] ?? null),
            secretCipher: self::nullableString($row['secret_cipher'] ?? null),
            secretRotatedAt: self::nullableString($row['secret_rotated_at'] ?? null),
            retentionDays: isset($row['retention_days'])
                ? (int) $row['retention_days']
                : self::DEFAULT_RETENTION_DAYS,
        );
    }

    /**
     * Whether a poster can work at all.
     *
     * Distinct from {@see $enabled}: a club that has generated no secret has no
     * poster in the world, so there is nothing to refuse and nothing to accept.
     */
    public function hasSecret(): bool
    {
        return $this->secretHash !== null && $this->secretHash !== '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
