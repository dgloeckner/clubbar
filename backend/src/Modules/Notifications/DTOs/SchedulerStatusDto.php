<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * Whether a drain run has ever been observed, and what to schedule if not
 * (#405, ADR-0038 rule 3).
 *
 * The instructions travel with the status on purpose. Three surfaces ask this
 * question — the installer's prerequisite step, the admin banner, and the
 * refusal a blocked finalize returns — and all three have to print the *same*
 * command. Assembling it separately in each is how two of them end up naming a
 * path that does not exist on this host.
 */
final readonly class SchedulerStatusDto
{
    public function __construct(
        /**
         * Has a run ever been recorded? Never re-answers `false` once true:
         * a scheduler that dies later is #406's alarm, not a lockout, because
         * refusing a collection at the moment the treasurer needs it is the
         * worse failure.
         */
        public bool $verified,
        /** UTC, or null on an installation that has never been drained. */
        public ?string $lastRunAt,
        /** `cli` or `url` — how the last observed run was triggered. */
        public ?string $source,
        public ?int $lastSent,
        public ?int $lastFailed,
        /** The *drain's* interpreter, which is frequently not the web's. */
        public ?string $phpVersion,
        /**
         * Extensions this application needs that the drain's PHP did not
         * load. `null` predates migration 027; `[]` means checked and
         * complete, which is a different statement worth keeping apart.
         *
         * @var list<string>|null
         */
        public ?array $missingExtensions,
        /** The command to paste into a panel's cron form. */
        public string $cliCommand,
        /**
         * The URL trigger, or null when `cron.secret` is unset — in which case
         * the route is not mounted and offering the URL would be a lie.
         */
        public ?string $drainUrl,
        /** Minutes between ticks the setup instructions recommend. */
        public int $recommendedIntervalMinutes,
        /**
         * What this installation says its scheduler does (ADR-0039 decision 5).
         * Every retry and alarm threshold is measured in ticks of it.
         */
        public string $declaredInterval = 'hourly',
        /**
         * What the gap between the last two observed runs looks like, or null
         * while there has been only one run — or a gap too short to conclude
         * anything from.
         */
        public ?string $observedInterval = null,
        /**
         * The declaration is slower than reality allows.
         *
         * Reported rather than acted on: the self-check shows the
         * disagreement, and nothing silently overrides what the admin
         * declared. A declaration that turns out to be wrong is a fact about
         * the host that somebody has to go and fix in a hosting panel, and
         * quietly re-deriving it would hide exactly that.
         */
        public bool $intervalDisagrees = false,
    ) {}

    /** Everything, for the office that holds the server. */
    public function toArray(): array
    {
        return [
            'declared_interval' => $this->declaredInterval,
            'observed_interval' => $this->observedInterval,
            'interval_disagrees' => $this->intervalDisagrees,
            'verified' => $this->verified,
            'last_run_at' => $this->lastRunAt,
            'source' => $this->source,
            'last_sent' => $this->lastSent,
            'last_failed' => $this->lastFailed,
            'php_version' => $this->phpVersion,
            'missing_extensions' => $this->missingExtensions,
            'setup' => [
                'cli_command' => $this->cliCommand,
                'drain_url' => $this->drainUrl,
                'recommended_interval_minutes' => $this->recommendedIntervalMinutes,
            ],
        ];
    }

    /**
     * The half a club office may read (#677).
     *
     * An allow-list, not a copy of `toArray()` with keys unset: a field added
     * to this DTO next year must not reach a Kassenwart because somebody
     * forgot a second `unset`. Whatever is added is admin-only until a human
     * writes it into this method.
     *
     * What survives is the answer to one question — *is a scheduled run
     * missing, and how often should it fire* — which is exactly what the
     * banner needs to warn the treasurer before the finalize button refuses
     * them. What does not survive is every field that describes the
     * deployment: the CLI command carries this installation's document root,
     * the drain URL names the trigger endpoint, and `php_version`,
     * `missing_extensions` and the interval pair are the self-check's reading
     * of the host. None of it is actionable by an office that cannot reach a
     * hosting panel, and all of it is the class of detail ADR-0031 keeps
     * behind the operator's session.
     *
     * `setup` stays an object with one key rather than flattening, so the
     * banner reads the same path whichever office it renders for.
     */
    public function toOfficeArray(): array
    {
        return [
            'verified' => $this->verified,
            'setup' => [
                'recommended_interval_minutes' => $this->recommendedIntervalMinutes,
            ],
        ];
    }
}
