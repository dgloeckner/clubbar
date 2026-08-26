<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

/**
 * One archive the remote store already holds.
 *
 * Carries the drive it was found in, so a delete can only ever name something
 * a listing produced. That is not ceremony: the credential this app holds can
 * delete anything in the library (the gap #691 names openly — `Sites.Selected`
 * has no add-only role), so the narrowest useful discipline available is that
 * the code has no way to express a delete of something it did not just see.
 *
 * Part of #691, epic #686.
 */
final readonly class RemoteArchive
{
    public function __construct(
        public string $id,
        public string $name,
        public int $size,
        public string $driveId,
    ) {
    }

    /**
     * The UTC instant in `clubbar-20260825-030000-1a2b3c4d.cbb`, or null.
     *
     * From the name rather than from the store's own `createdDateTime`,
     * because the two disagree exactly when it matters: an archive re-uploaded
     * after a failed night is *new* to the store and old to the club, and
     * remote retention must age it by when the snapshot was taken.
     */
    public function createdAt(): ?int
    {
        if (preg_match('/^clubbar-(\d{8}-\d{6})-/', $this->name, $m) !== 1) {
            return null;
        }

        $at = \DateTimeImmutable::createFromFormat('Ymd-His', $m[1], new \DateTimeZone('UTC'));

        return $at === false ? null : $at->getTimestamp();
    }
}
