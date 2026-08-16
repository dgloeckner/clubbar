<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Notifications\DTOs\MailConfigDto;
use PDO;

/**
 * `mail_config`'s operational dials, at the seam that stores them
 * (ADR-0039, #462 step 11.4).
 *
 * The singleton is shared with every other suite, so each test here restores
 * what it changed. Nothing is created, so `SchemaTestCase`'s cleanup has
 * nothing to do — it is extended for `assertDatabaseRefuses()`.
 */
class MailConfigSchemaTest extends SchemaTestCase
{
    private ?string $originalCadence = null;

    protected function tearDown(): void
    {
        if ($this->originalCadence !== null) {
            $this->db->prepare('UPDATE mail_config SET statement_cadence = ? WHERE id = 1')
                ->execute([$this->originalCadence]);
            $this->originalCadence = null;
        }

        parent::tearDown();
    }

    public function test_the_cadence_column_offers_exactly_three_values(): void
    {
        $type = $this->columnType('statement_cadence');

        $this->assertSame("enum('off','monthly','quarterly')", $type);
    }

    /**
     * `weekly` is refused by the column, not only by a rule list.
     *
     * ADR-0039 decision 3 rejects it as a *product* decision — fifty-two
     * itemised statements a year makes "predictable" read as "relentless" — and
     * a decision of that kind is one somebody will reasonably want to reopen.
     * Keeping it out of the enum means reopening it is a migration and a
     * conversation, rather than an edit to a validator nobody reviews.
     */
    public function test_weekly_is_not_a_storable_cadence(): void
    {
        $this->rememberCadence();

        $this->assertDatabaseRefuses(
            fn () => $this->db->prepare('UPDATE mail_config SET statement_cadence = ? WHERE id = 1')
                ->execute(['weekly']),
            'a weekly cadence must not be storable — ADR-0039 decision 3 rejects it by design'
        );
    }

    /**
     * The column's default and the singleton's value differ, deliberately.
     *
     * The DEFAULT says what ADR-0039 wants — a feature nobody has to discover.
     * The row says what migration 039 may do on the day it runs — which is
     * never "start mailing a live membership as a side effect of an upgrade".
     * Both halves are asserted because the migration is only correct as a pair,
     * and a later edit that silently aligned them would change what an upgrade
     * does to real members.
     */
    public function test_the_default_is_monthly_and_the_migrated_row_is_off(): void
    {
        $this->assertStringContainsString(
            'monthly',
            (string) $this->column('statement_cadence')['Default'],
            'the column default states ADR-0039 decision 3'
        );

        $stored = $this->db->query('SELECT statement_cadence FROM mail_config WHERE id = 1')->fetchColumn();

        $this->assertSame(
            'off',
            $stored,
            'migration 039 leaves every installation switched off; #463 (P12) supplies the content and the switch'
        );
    }

    /** The dials 029 added are still here, and still bounded. */
    public function test_the_scheduler_dials_survive_beside_the_cadence(): void
    {
        $this->assertSame(
            "enum('fifteen_minutes','hourly','daily')",
            $this->columnType('cron_interval'),
            'migration 040 adds the cadence bin/cron.php has recommended since #403'
        );
        $this->assertNotNull($this->column('drain_batch_size'));
    }

    /**
     * `weekly` is refused by the column, not only by a rule list — and adding a
     * *faster* case in 040 does not soften that. The argument against it is
     * about the § 7 Abs. 3 announcement distance collapsing, which a
     * fifteen-minute case has nothing to do with.
     */
    public function test_weekly_is_still_not_a_storable_interval(): void
    {
        $this->assertDatabaseRefuses(
            fn () => $this->db->prepare('UPDATE mail_config SET cron_interval = ? WHERE id = 1')
                ->execute(['weekly']),
            'a weekly scheduler must not be storable — 029 refuses it and 040 does not reopen it'
        );
    }

    /**
     * The run budget becomes a club-editable dial in 040, and it ships at 25
     * seconds rather than the 50 the constant used to be.
     *
     * The default is the assertion that matters: it has to be reachable inside
     * the tightest timeout that can trigger a drain — an external HTTP
     * scheduler's 30 seconds — because overshooting means a run killed mid-send,
     * whose claimed rows come back after the stale window and are offered to the
     * transport a second time.
     */
    public function test_the_run_budget_defaults_below_the_tightest_trigger_timeout(): void
    {
        $default = (int) $this->column('drain_budget_seconds')['Default'];

        $this->assertSame(MailConfigDto::DEFAULT_DRAIN_BUDGET_SECONDS, $default);
        $this->assertLessThan(30, $default);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function rememberCadence(): void
    {
        $this->originalCadence = (string) $this->db
            ->query('SELECT statement_cadence FROM mail_config WHERE id = 1')
            ->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     *
     * `SHOW COLUMNS` is filtered in PHP rather than with `LIKE ?`: the
     * connection uses native prepares, and a placeholder is not allowed there.
     */
    private function column(string $name): array
    {
        foreach ($this->db->query('SHOW COLUMNS FROM mail_config')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['Field'] === $name) {
                return $row;
            }
        }

        $this->fail("mail_config has no column {$name}");
    }

    private function columnType(string $name): string
    {
        return strtolower((string) $this->column($name)['Type']);
    }
}
