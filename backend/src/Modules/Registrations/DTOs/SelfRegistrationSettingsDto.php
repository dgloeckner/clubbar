<?php

declare(strict_types=1);

namespace App\Modules\Registrations\DTOs;

use App\Shared\Utils\DateFormatter;

/**
 * The settings screen's view of self-registration (#783, UC-A69).
 *
 * Deliberately carries **no secret material** — not the hash, not the cipher,
 * not the secret itself. An admin who wants the secret asks for the poster,
 * which is a separate, audited action; a settings payload that included it
 * would put a live credential into every page load, every browser cache and
 * every screen share of this screen.
 *
 * What it does carry is `has_secret` and when it was last rotated, which is all
 * the screen needs to say "a poster exists, printed in March".
 */
final readonly class SelfRegistrationSettingsDto
{
    public function __construct(
        public bool $enabled,
        public ?string $disabledReason,
        public bool $hasSecret,
        public ?string $secretRotatedAt,
        public ?string $documentUrl,
        public int $retentionDays,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'disabled_reason' => $this->disabledReason,
            'has_secret' => $this->hasSecret,
            // An instant, labelled UTC on the way out (#365) — the screen
            // renders it with the time of day, and a bare datetime would be
            // read by the browser as local.
            'secret_rotated_at' => DateFormatter::toUtcIso($this->secretRotatedAt),
            'document_url' => $this->documentUrl,
            'retention_days' => $this->retentionDays,
        ];
    }
}
