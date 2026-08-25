<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Guards the factual claims this repository makes about backups.
 *
 * Prose has no behaviour to test, so most of a documentation change is not
 * testable and should not pretend to be. The claims below are the exception:
 * each is a statement about the world that was **false**, silently, for as long
 * as nobody re-read the file.
 *
 *   - `README.md` advertised "automated daily backups with 30-day retention"
 *     as a shipped feature. No code in this repository implemented it.
 *   - `docs/deployment.md` documented a `mysqldump` + `crontab -e` procedure as
 *     *the* backup procedure, on a project whose reference host (IONOS shared
 *     webhosting) has neither a shell nor a crontab — as the same document says
 *     two hundred lines further down.
 *
 * A reader who followed the documentation to the letter ended up with no
 * backups, believing they had them. That is the failure mode worth a test: not
 * "is the wording good" but "does this file still claim something untrue".
 *
 * See #686 (epic), #687 (this slice) and #699, which added the role-word guards
 * after the ADR shipped naming the Kassenwart as a backup key holder. #703 adds
 * the guards over the amended design — the on-switch wording, and the three
 * things the course correction removed, each of which the documentation
 * described at length and would otherwise still describe.
 */
class BackupDocumentationTest extends TestCase
{
    private const ADR = 'adr/0049-encrypted-offsite-backups-on-shared-hosting.md';
    private const USE_CASE = 'use-cases/admin/UC-A83-database-backup.md';

    public function test_the_backup_adr_exists(): void
    {
        $this->assertFileExists(
            self::repoRoot() . '/' . self::ADR,
            'ADR-0049 records why backups are produced in-app, sealed to a keypair of '
            . 'their own, and pushed off the host. Without it the design lives only in '
            . 'an issue thread.'
        );
    }

    public function test_the_backup_adr_is_listed_in_the_adr_index(): void
    {
        // An ADR nobody can find from the index is an ADR nobody reads.
        $this->assertStringContainsString(
            '0049-encrypted-offsite-backups-on-shared-hosting.md',
            self::read('adr/README.md'),
            'adr/README.md must link ADR-0049 in its ADR table.'
        );
    }

    public function test_the_backup_use_case_exists_and_is_indexed(): void
    {
        $this->assertFileExists(self::repoRoot() . '/' . self::USE_CASE);
        $this->assertStringContainsString(
            'UC-A83',
            self::read('use-cases/README.md'),
            'use-cases/README.md must list UC-A83 under Admin Panel → System.'
        );
    }

    /**
     * The claim that started this: a feature advertised in the README that no
     * line of code implements.
     */
    public function test_the_readme_does_not_advertise_automated_backups_as_shipped(): void
    {
        $readme = self::read('README.md');

        $this->assertStringNotContainsString(
            'automated daily backups with 30-day retention',
            $readme,
            'README.md:179 advertised a feature that does not exist. Until #686 ships, '
            . 'the README must describe what the deployment guide actually documents.'
        );
    }

    /**
     * The `mysqldump | gzip | gpg -c` + `crontab -e` recipe is fine advice — for
     * a VPS. On the reference host it cannot run at all, so it must be labelled
     * rather than deleted: a self-hoster on a root server still wants it.
     *
     * The assertion is deliberately about *ordering*, not wording: the shell
     * recipe must appear underneath a heading that scopes it to a machine with a
     * shell. That survives rewording; it fails if the recipe is ever promoted
     * back to being the general procedure.
     */
    public function test_the_shell_backup_recipe_is_scoped_to_hosts_with_a_shell(): void
    {
        $deployment = self::read('docs/deployment.md');

        $cronRecipe = strpos($deployment, 'crontab -e');
        $this->assertNotFalse($cronRecipe, 'The VPS backup recipe has gone missing entirely.');

        $scopingHeading = self::headingOffsetMatching($deployment, '/^#{2,4} .*\b(VPS|root shell|own server)\b/mi');
        $this->assertNotNull(
            $scopingHeading,
            'docs/deployment.md must carry a heading scoping the shell backup recipe to a '
            . 'host with a shell. The reference host (IONOS shared hosting) has none — '
            . 'docs/deployment.md itself says so under "IONOS specifically".'
        );

        $this->assertLessThan(
            $cronRecipe,
            $scopingHeading,
            'The `crontab -e` recipe must sit underneath the heading that scopes it, not '
            . 'above it — otherwise it still reads as the procedure for every host.'
        );
    }

    /**
     * The backup private keys are the Admin's, and the docs must not drift back.
     *
     * This is one mistake wearing two hats. The *office* is wrong: ADR-0049
     * rejects sealing backups to the IBAN keypair precisely because the
     * Kassenwart holds a copy of it and would thereby hold the audit log, every
     * admin's TOTP ciphertext and the database password — and then handing them a
     * backup key instead re-crosses the same boundary through a different key.
     * The ADR shipped saying both things, six lines apart.
     *
     * The *word* is wrong too: CONTEXT.md lists "treasurer" under Avoid, because
     * the club says Kassenwart. A doc that says "treasurer" is usually a doc that
     * stopped thinking about which office it meant, which is how the first
     * mistake gets in.
     *
     * See #699.
     */
    public function test_the_backup_docs_do_not_hand_a_backup_key_to_the_kassenwart(): void
    {
        foreach ([self::ADR, self::USE_CASE] as $relativePath) {
            $text = self::read($relativePath);

            $this->assertDoesNotMatchRegularExpression(
                '/(private half|recipients?|key holders?)[^.]{0,80}\bKassenwart\b/i',
                $text,
                $relativePath . ' names the Kassenwart as a backup key holder. Backups belong '
                . 'to the Admin (ADR-0049 decision 2) — a backup carries the audit log, every '
                . "admin's TOTP ciphertext and the database password, which is the boundary "
                . 'the "not the IBAN keypair" decision exists to hold.'
            );
        }
    }

    public function test_the_backup_docs_say_kassenwart_rather_than_treasurer(): void
    {
        foreach ([self::ADR, self::USE_CASE, 'docs/deployment.md'] as $relativePath) {
            $this->assertStringNotContainsStringIgnoringCase(
                'treasurer',
                self::read($relativePath),
                $relativePath . ' says "treasurer". CONTEXT.md lists that word under Avoid — '
                . 'the club says Kassenwart, in code, specs and UI alike.'
            );
        }
    }

    /**
     * The on-switch, said in plain words where an operator will meet it.
     *
     * `backup.recipient_public_keys` being present *is* what turns nightly
     * backups on (ADR-0049 decision 2), and the sample config is the file a
     * club actually edits. A reader who takes it for "one setting among
     * several" configures a key and then goes looking for the switch — or
     * worse, does not configure one and waits for backups that were never on.
     */
    public function test_the_sample_config_says_that_a_key_is_the_on_switch(): void
    {
        $sample = self::read('package/config.sample.php');

        $this->assertStringContainsString(
            'recipient_public_keys',
            $sample,
            'The key was renamed from backup.public_keys by #703; the sample must use the '
            . 'name the application actually reads.'
        );
        $this->assertMatchesRegularExpression(
            '/CONFIGURING AT LEAST ONE RECIPIENT KEY SWITCHES NIGHTLY BACKUPS ON;\s*\n?\s*\/\/\s*'
            . 'REMOVE ALL KEYS TO SWITCH THEM OFF/i',
            $sample,
            'config.sample.php must say, in plain words, that configuring a recipient key '
            . 'switches backups on and removing every key switches them off. There is no '
            . 'enabled flag to find instead.'
        );
    }

    /**
     * The old configuration key must not survive anywhere a club would copy it
     * from.
     *
     * A `config.php` still saying `public_keys` is not a syntax error and not a
     * warning — it is an installation with no recipient key, which under the
     * amended design means no backups at all, silently. Exactly the class of
     * false documentation this file exists for.
     */
    public function test_no_document_still_names_the_old_configuration_key(): void
    {
        foreach ([self::ADR, self::USE_CASE, 'docs/deployment.md', 'package/config.sample.php'] as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/backup\.public_keys|[\'"]public_keys[\'"]/',
                self::read($path),
                $path . ' still names `backup.public_keys`. It was renamed to '
                . '`backup.recipient_public_keys` by #703, and a config.php carrying the old '
                . 'name configures no recipient at all — which now means no backups, silently.'
            );
        }
    }

    /**
     * The three things the course correction removed, each of which the
     * documentation used to explain at length (#703, ADR-0049 decision 8).
     *
     * Prose outlives code by default. A deployment guide that still tells a
     * club to refill the bank codes after a restore sends a volunteer looking
     * for a panel button that no longer exists — at the one moment they are
     * least able to absorb a wrong instruction.
     */
    public function test_the_docs_no_longer_describe_the_removed_state_layer(): void
    {
        foreach ([self::USE_CASE, 'docs/deployment.md', 'package/config.sample.php'] as $path) {
            $text = self::read($path);

            $this->assertDoesNotMatchRegularExpression(
                '/\bbackup_runs\b|\bbackup_keys\b|\bbackup_config\b/',
                $text,
                $path . ' still describes the backup tracking tables. #703 removed them: the '
                . 'archive header is the record and the journal beside it is the history.'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/(SCHEMA_ONLY|schema-only|structure-only)/i',
                $text,
                $path . ' still describes a table as schema-only. Every base table is dumped in '
                . 'full (ADR-0049 decision 1 as amended), so a restored installation needs no '
                . 'repopulation step.'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/bank-codes\/reimport|refill the bank codes/i',
                $text,
                $path . ' still points at the bank-codes re-import endpoint. It existed only to '
                . 'compensate for the schema-only class and was deleted with it.'
            );
        }
    }

    /**
     * The data-model approval for the three tables was withdrawn, so the ERM
     * must not document them — and no migration may create them.
     *
     * `docs/erm-master.md` is the data model per CLAUDE.md, which makes a table
     * described there a table somebody may reasonably build against. This is
     * the guard that would catch the tables coming back through a later slice
     * that only read the ERM.
     */
    public function test_the_data_model_carries_no_backup_tables_and_no_migration_creates_one(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/###\s+backup_(runs|keys|config)/',
            self::read('docs/erm-master.md'),
            'The data-model approval for backup_runs/backup_keys/backup_config was withdrawn '
            . '(#703). The feature touches the application schema nowhere.'
        );

        $creating = array_values(array_filter(
            glob(self::repoRoot() . '/backend/db/migrations/*.sql') ?: [],
            static fn (string $file): bool => preg_match(
                '/CREATE TABLE\s+`?backup_/i',
                (string) file_get_contents($file)
            ) === 1
        ));

        $this->assertSame([], array_map('basename', $creating), 'No migration may create a backup table.');
    }

    /**
     * The docs say the installer prints the backup cron line beside the
     * drain's. It has to actually print it.
     *
     * This is the same failure as the README claim at the top of this file,
     * one step further down: `docs/deployment.md` and UC-A83 both told a club
     * the installer would hand them both lines, and step 5 printed only the
     * drain's. Nothing else reminds anyone about the backup job — it blocks no
     * workflow, unlike the drain, which refuses finalize until it has run — so
     * a volunteer who is told the installer prints it, and does not see it,
     * adds one cron line and believes they are done.
     *
     * Asserted as a *pair*: the claim and the thing claimed. Either may be
     * changed, but not one without the other.
     */
    public function test_the_installer_prints_the_backup_cron_line_the_docs_promise(): void
    {
        $installer = self::read('package/install.php');

        $this->assertStringContainsString(
            'backend/bin/backup.php',
            $installer,
            'Step 5 of the installer must print the backup entrypoint, because '
            . 'docs/deployment.md and UC-A83 both say it does.'
        );
        $this->assertStringContainsString(
            'backup.recipient_public_keys',
            $installer,
            'The printed line writes nothing until a recipient key is configured (ADR-0049 '
            . 'decision 2), and the installer cannot configure one — the keypairs are '
            . 'generated offline. So it has to say what turns the job on, or it hands the '
            . 'club a scheduled job that silently does nothing.'
        );

        foreach ([self::USE_CASE, 'docs/deployment.md'] as $path) {
            $this->assertMatchesRegularExpression(
                '/installer prints/i',
                self::read($path),
                $path . ' no longer claims the installer prints the cron lines. If that is '
                . 'deliberate, this guard and the installer step should go together.'
            );
        }
    }

    /** Offset of the first heading matching $pattern, or null. */
    private static function headingOffsetMatching(string $haystack, string $pattern): ?int
    {
        if (preg_match($pattern, $haystack, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $m[0][1];
    }

    private static function read(string $relativePath): string
    {
        $path = self::repoRoot() . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The offline decryptor is the file a key holder reads at the worst moment
     * of the club's year, so who it tells them to be matters more here than
     * anywhere else in the repository.
     *
     * Two things are guarded, and they failed together in the first draft.
     * `tools/backup-decryptor.html` said *"A treasurer downloads the archive"*:
     * the wrong office — backups belong to the Admin (ADR-0049 decision 2),
     * because a backup carries the audit log, every admin's TOTP ciphertext and
     * the database password — and a word `CONTEXT.md:95` lists under **Avoid**,
     * since the club says *Kassenwart*, never *treasurer*.
     *
     * The sources are included as well as the tool: the same sentence was in
     * `BackupSealedBox`'s class docblock, six lines from the paragraph
     * explaining why it could not be true.
     */
    public function test_the_backup_tool_and_container_name_the_right_key_holder(): void
    {
        $guarded = [
            'tools/backup-decryptor.html',
            'tools/backup-decryptor.js',
            'backend/src/Shared/Security/BackupSealedBox.php',
        ];

        foreach ($guarded as $relativePath) {
            $text = self::read($relativePath);

            $this->assertStringNotContainsStringIgnoringCase(
                'treasurer',
                $text,
                $relativePath . ' says "treasurer". CONTEXT.md lists that word under Avoid — '
                . 'the club says Kassenwart, in code, specs and UI alike.'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/(private half|recipients?|key holders?)[^.]{0,80}\bKassenwart\b/i',
                $text,
                $relativePath . ' names the Kassenwart as a backup key holder. They hold the '
                . 'IBAN private key because SEPA collection is impossible without it; a backup '
                . 'key would re-cross that boundary through a second keypair (ADR-0049 '
                . 'decision 2).'
            );
        }
    }

    /**
     * The worked example a reader copies, so it has to demonstrate the custody
     * the ADR requires rather than the one its first draft assumed.
     */
    public function test_the_golden_fixture_is_sealed_to_the_offices_that_hold_backup_keys(): void
    {
        $archive = (string) file_get_contents(
            dirname(__DIR__, 2) . '/Fixtures/backup/golden.cbb'
        );

        $this->assertSame(
            ['admin', 'vorstand'],
            array_column(\App\Shared\Security\BackupSealedBox::readHeader($archive)['recipients'], 'label'),
            'The committed fixture is the example a reader copies. Sealing it to the '
            . 'Kassenwart would demonstrate exactly the custody ADR-0049 decision 2 forbids.'
        );
    }

    /**
     * These files live above `./backend`, which is the only thing the backend
     * container mounts at `/app` — the same problem CheckPatchCoverageScriptTest
     * hit with `scripts/`, solved the same way, with a read-only mount.
     */
    private static function repoRoot(): string
    {
        // CI and the host both run phpunit from inside a full checkout.
        $checkout = dirname(__DIR__, 4);
        if (is_dir($checkout . '/adr') && is_dir($checkout . '/use-cases')) {
            return $checkout;
        }

        // The documented container workflow; see docker-compose.yml. `tools/`
        // is checked too, because a compose file predating the decryptor would
        // otherwise resolve here and fail on a missing file rather than saying
        // the mount is stale.
        if (is_dir('/repo/adr') && is_dir('/repo/tools')) {
            return '/repo';
        }

        self::fail(
            'Cannot locate the repository docs. Inside the backend container they are '
            . 'mounted read-only at /repo — run `docker compose up -d` after pulling a '
            . 'compose file that carries those mounts.'
        );
    }
}
