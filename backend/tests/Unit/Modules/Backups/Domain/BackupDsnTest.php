<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Domain;

use App\Modules\Backups\Domain\BackupDsn;
use App\Modules\Backups\Domain\BackupDsnException;
use PHPUnit\Framework\TestCase;

/**
 * The one configuration value that selects where archives are pushed.
 *
 * Same shape as `mail.dsn` (ADR-0038 rule 2, ADR-0049 decision 6) and for the
 * same reason: one field the club fills in, one place the storage target can
 * be swapped without touching the producer.
 *
 * This class parses and validates; it never connects. Validation catches what
 * is cheap to catch — a typo'd scheme, a missing library — and says so in a
 * sentence the self-check can print. Everything else (a revoked secret, a site
 * the app was never granted) can only be learned by uploading, and is reported
 * per run instead.
 *
 * **A malformed DSN must never read as "no remote configured".** That is the
 * failure this file exists to prevent: a club that typed the DSN and believes
 * its archives are leaving the host, while a silent fallback keeps them local.
 *
 * Part of #691, epic #686.
 */
class BackupDsnTest extends TestCase
{
    private const VALID = 'msgraph://11111111-2222-3333-4444-555555555555/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'
        . '@verein.sharepoint.com/Dokumente/Backups';

    public function test_a_full_dsn_yields_every_part_the_transport_needs(): void
    {
        $dsn = BackupDsn::parse(self::VALID);

        $this->assertSame('msgraph', $dsn->scheme);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $dsn->tenantId);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $dsn->clientId);
        $this->assertSame('verein.sharepoint.com', $dsn->site);
        $this->assertSame('Dokumente', $dsn->library);
        $this->assertSame('Backups', $dsn->path);
    }

    /**
     * The folder is optional: a club that points at the root of a library has
     * said something complete, not something half-typed.
     */
    public function test_the_folder_path_may_be_omitted(): void
    {
        $dsn = BackupDsn::parse(
            'msgraph://tenant/client@verein.sharepoint.com/Dokumente'
        );

        $this->assertSame('Dokumente', $dsn->library);
        $this->assertSame('', $dsn->path);
    }

    /** Nested folders are one path, not a special case. */
    public function test_a_nested_folder_path_survives_intact(): void
    {
        $dsn = BackupDsn::parse(
            'msgraph://tenant/client@verein.sharepoint.com/Dokumente/EDV/Backups/2026'
        );

        $this->assertSame('EDV/Backups/2026', $dsn->path);
    }

    public function test_the_reserved_schemes_are_refused_by_name_rather_than_as_gibberish(): void
    {
        // s3:// and hidrive:// are named in ADR-0049 as the roadmap. Refusing
        // them with "not built yet" rather than "unknown scheme" is the
        // difference between a club waiting for a release and a club deciding
        // it typed something wrong.
        foreach (['s3://key@bucket/path', 'hidrive://user@host/path'] as $dsn) {
            try {
                BackupDsn::parse($dsn);
                $this->fail('Expected ' . $dsn . ' to be refused.');
            } catch (BackupDsnException $e) {
                $this->assertMatchesRegularExpression('/not built yet|only .*msgraph/i', $e->getMessage());
            }
        }
    }

    public function test_an_unknown_scheme_says_what_is_supported(): void
    {
        $this->expectException(BackupDsnException::class);
        $this->expectExceptionMessageMatches('/msgraph/');

        BackupDsn::parse('ftp://host/path');
    }

    public function test_a_value_with_no_scheme_is_refused(): void
    {
        $this->expectException(BackupDsnException::class);

        BackupDsn::parse('verein.sharepoint.com/Dokumente');
    }

    /**
     * Each missing part is named. A club reading "backup.dsn is invalid" has to
     * guess; a club reading "no library" edits one word.
     */
    public function test_each_missing_part_names_itself(): void
    {
        $cases = [
            'msgraph://tenant@site/library' => '/client/i',
            'msgraph://tenant/client@' => '/site/i',
            'msgraph://tenant/client@verein.sharepoint.com' => '/library/i',
        ];

        foreach ($cases as $dsn => $expected) {
            try {
                BackupDsn::parse($dsn);
                $this->fail('Expected ' . $dsn . ' to be refused.');
            } catch (BackupDsnException $e) {
                $this->assertMatchesRegularExpression($expected, $e->getMessage(), 'for ' . $dsn);
            }
        }
    }

    /**
     * The secret is deliberately not in the DSN.
     *
     * `backup.client_secret` is its own config key for the same reason the SMTP
     * password is kept out of a URL: a DSN gets pasted into support threads,
     * issue reports and screenshots, and a credential that travels with it
     * leaks by ordinary helpfulness rather than by attack.
     */
    public function test_a_dsn_carrying_a_password_is_refused_rather_than_quietly_accepted(): void
    {
        $this->expectException(BackupDsnException::class);
        $this->expectExceptionMessageMatches('/client_secret/');

        BackupDsn::parse('msgraph://tenant/client:s3cr3t@verein.sharepoint.com/Dokumente/Backups');
    }

    /**
     * What gets logged and shown in the self-check. The tenant and client ids
     * are not secrets, but the whole DSN in a log line is noise; what an
     * operator needs is "which site am I pushing to".
     */
    /**
     * The shape the onboarding script prints, and the one to prefer.
     *
     * A drive id needs no resolution, so a run makes two fewer requests and
     * cannot fail on a name — and a library's display name *is* a name that
     * fails: it is localised per tenant, so the same library is `Dokumente`
     * in one and `Documents` in another.
     */
    public function test_a_drive_addressed_dsn_names_the_target_outright(): void
    {
        $dsn = BackupDsn::parse('msgraph://tenant/client@drive/b!xY9_z-Q/clubbar');

        $this->assertTrue($dsn->addressesDriveDirectly());
        $this->assertSame('b!xY9_z-Q', $dsn->driveId);
        $this->assertSame('clubbar', $dsn->path);
        $this->assertSame('', $dsn->site);
        $this->assertSame('', $dsn->library);
    }

    public function test_a_site_addressed_dsn_says_it_is_not_a_drive(): void
    {
        $this->assertFalse(BackupDsn::parse(self::VALID)->addressesDriveDirectly());
    }

    public function test_the_word_drive_with_no_id_after_it_names_what_is_missing(): void
    {
        $this->expectException(BackupDsnException::class);
        $this->expectExceptionMessageMatches('/drive id/i');

        BackupDsn::parse('msgraph://tenant/client@drive');
    }

    /**
     * `Sites.Selected` is granted per site *collection*, so pointing the DSN at
     * the root site would grant this app write access to the club intranet and
     * every library under it. A site path is one DSN segment, written with
     * colons — the shape that lets a dedicated site be named at all.
     */
    public function test_a_site_path_is_written_with_colons_and_becomes_a_graph_selector(): void
    {
        $this->assertSame(
            'frgs.sharepoint.com:/sites/Backups:',
            BackupDsn::parse('msgraph://t/c@frgs.sharepoint.com:sites:Backups/Backups')->graphSiteSelector()
        );

        $this->assertSame(
            'verein.sharepoint.com',
            BackupDsn::parse(self::VALID)->graphSiteSelector()
        );
    }

    public function test_it_describes_itself_without_reprinting_the_whole_dsn(): void
    {
        $described = BackupDsn::parse(self::VALID)->describe();

        $this->assertStringContainsString('verein.sharepoint.com', $described);
        $this->assertStringContainsString('Dokumente', $described);
        $this->assertStringNotContainsString('11111111-2222', $described);
    }

    /** A drive id identifies nothing to a reader; its tail tells two targets apart. */
    public function test_describing_a_drive_addressed_dsn_does_not_print_the_whole_drive_id(): void
    {
        $described = BackupDsn::parse('msgraph://tenant/client@drive/b!veryLongOpaqueDriveIdentifier/clubbar')
            ->describe();

        $this->assertStringNotContainsString('veryLongOpaque', $described);
        $this->assertStringContainsString('clubbar', $described);
    }
}
