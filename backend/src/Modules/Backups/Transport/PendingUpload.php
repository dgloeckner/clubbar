<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

/**
 * A readable, unexpired {@see UploadState}: the session and how far it got.
 *
 * `uploaded` is what *this side* last saw acknowledged, and it is a hint, not
 * a fact — the server's own `nextExpectedRanges` wins wherever they disagree,
 * which is exactly the case where a chunk landed and its response was lost.
 *
 * Part of #691, epic #686.
 */
final readonly class PendingUpload
{
    public function __construct(
        public string $uploadUrl,
        public int $expiresAt,
        public int $uploaded,
        public int $size,
    ) {
    }
}
