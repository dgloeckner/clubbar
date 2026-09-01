<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

/**
 * Fetch the club's published Anmeldung (#780, ADR-0052 decision 5a).
 *
 * An interface for one reason: no test opens a socket. The document lives at a
 * URL the club controls, and clubbar pins no copy — deliberately, and the
 * reasoning is in the ADR: the backup dumper walks `information_schema.TABLES`
 * only (ADR-0049) while the upgrade script preserves `backend/storage/`, so a
 * pinned file would survive an upgrade and vanish on restore, leaving the row
 * that claimed it was pinned pointing at nothing.
 */
interface TemplateFetcher
{
    /**
     * The bytes at $url, or null if they could not be had.
     *
     * Never throws, and never distinguishes *why* it failed. The caller's
     * decision is the same for a 404, a timeout and a DNS failure — carry on
     * without a document — and a fetcher that raised different exceptions for
     * them would invite a caller to care about a difference that changes
     * nothing.
     */
    public function fetch(string $url, int $timeoutSeconds = 10): ?string;
}
