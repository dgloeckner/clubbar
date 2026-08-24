<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Guards the factual claims this repository makes about backups.
 *
 * Prose has no behaviour to test, so most of a documentation change is not
 * testable and should not pretend to be. These four claims are the exception:
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
 * See #686 (epic) and #687 (this slice).
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

        // The documented container workflow; see docker-compose.yml.
        if (is_dir('/repo/adr')) {
            return '/repo';
        }

        self::fail(
            'Cannot locate the repository docs. Inside the backend container they are '
            . 'mounted read-only at /repo — run `docker compose up -d` after pulling a '
            . 'compose file that carries those mounts.'
        );
    }
}
