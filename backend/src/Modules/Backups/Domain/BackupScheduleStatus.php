<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * The backup job's half of `GET /api/admin/scheduler` (#693).
 *
 * Four fields, and each is here because the client cannot derive it: two are
 * state only the server has observed, and two vary with where this installation
 * happens to be deployed.
 *
 * There is no schedule among them. **Triggering is external** — a hosting panel
 * fires the job, the application never reads a cadence and could not act on
 * one. The recommended `0 3 * * *` is the same on every installation, so it
 * belongs in the banner's own strings rather than in a payload field that would
 * dress advice up as configuration.
 *
 * ## Admin-only
 *
 * `GET /api/admin/scheduler` is granted to `TREASURY` — wider than the body it
 * returns, which is the one place in ADR-0044's table where that is true. This
 * section is admin-only within it, mirroring the surfaces it points at:
 * `GET /api/admin/security-check`, where the measured backup rows render, is
 * `ADMIN_ONLY`, and backup configuration is the operator's throughout.
 *
 * So it is assembled into `SchedulerStatusDto::toArray()` and never into
 * `toOfficeArray()`. A Kassenwart cannot reach a hosting panel and cannot act on
 * a missing backup cron; handing them a command naming the server's document
 * root would be the exposure #677's allow-list exists to prevent.
 */
final readonly class BackupScheduleStatus
{
    public function __construct(
        /** Are backups switched on? Configuring a recipient key is the switch. */
        public bool $configured,
        /**
         * Has a run ever been observed — from the journal and the archives on
         * disk, never from a table. No backup run history exists in the
         * database, by decision (ADR-0049 decision 8).
         */
        public bool $verified,
        /** The command to paste into a panel's cron form. */
        public string $cliCommand,
        /**
         * The URL trigger, or null when the route behind it is not mounted —
         * which needs both a cron secret and a recipient key.
         */
        public ?string $triggerUrl,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'configured'  => $this->configured,
            'verified'    => $this->verified,
            'cli_command' => $this->cliCommand,
            'trigger_url' => $this->triggerUrl,
        ];
    }
}
