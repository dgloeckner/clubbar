<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * How long archives are kept, and how much room they may take.
 *
 * **Compiled defaults, optional `config.php` overrides.** The earlier draft put
 * these in a `backup_config` row so the panel could edit them; that table went
 * with the rest of the backup's database state (ADR-0049 decision 8), and the
 * shape left behind is the honest one — every club wants the defaults, the few
 * that do not are already editing `config.php` for their recipient keys, and a
 * policy that a *restore* could silently revert was never a good place for the
 * number that bounds an ADR-0029 erasure.
 *
 * A nonsensical override is refused rather than obeyed: zero-day retention
 * would delete tonight's archive at the end of tonight's run, and a zero cap
 * would put every installation permanently over it. Both read as a typo, and
 * the compiled default is the better guess than either.
 *
 * ADR-0049 decisions 2 and 8. Part of #703, epic #686.
 */
final class BackupRetention
{
    /** Enough depth to notice a mistake made a few weeks ago, bounded by ADR-0029. */
    public const DEFAULT_LOCAL_DAYS = 30;

    /**
     * 1 GiB. Refused rather than exceeded: a full webspace quota breaks logging
     * and mandate storage, neither of which is the backup's to break.
     */
    public const DEFAULT_LOCAL_MAX_BYTES = 1073741824;

    /**
     * The remote's own window, longer because the remote is the copy that
     * survives losing the host. Enforced by #691, which owns the transport;
     * carried here so the whole retention policy is stated in one place and in
     * one config section rather than discovered twice.
     */
    public const DEFAULT_REMOTE_DAYS = 90;

    private function __construct(
        public readonly int $localDays,
        public readonly int $localMaxBytes,
        public readonly int $remoteDays,
    ) {
    }

    /**
     * @param ?int $localDays     `backup.local_retention_days`
     * @param ?int $localMaxBytes `backup.local_max_bytes`
     * @param ?int $remoteDays    `backup.remote_retention_days`
     */
    public static function fromOverrides(
        ?int $localDays = null,
        ?int $localMaxBytes = null,
        ?int $remoteDays = null,
    ): self {
        return new self(
            self::positiveOr($localDays, self::DEFAULT_LOCAL_DAYS),
            self::positiveOr($localMaxBytes, self::DEFAULT_LOCAL_MAX_BYTES),
            self::positiveOr($remoteDays, self::DEFAULT_REMOTE_DAYS),
        );
    }

    public static function defaults(): self
    {
        return self::fromOverrides();
    }

    private static function positiveOr(?int $override, int $default): int
    {
        return $override !== null && $override > 0 ? $override : $default;
    }
}
