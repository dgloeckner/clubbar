<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Domain;

use App\Modules\Backups\Domain\BackupDsn;
use App\Modules\Backups\Domain\BackupDsnException;
use App\Modules\Backups\Domain\HiDriveDsn;
use PHPUnit\Framework\TestCase;

/**
 * `hidrive://<user>@<host>/<absolute path>`, and the four ways to get it wrong.
 *
 * This value is typed by hand into `config.php` by a volunteer working from
 * `docs/hidrive-backup-target.md`, and nothing downstream re-derives it. So the
 * job here is less "does it parse" than **does a wrong one say which word to
 * edit** — the difference between a five-second fix and a fortnight of nightly
 * failures nobody reads.
 *
 * Part of #825, epic #686.
 */
class HiDriveDsnTest extends TestCase
{
    private const VALID = 'hidrive://clubbar-backup@webdav.hidrive.ionos.com/users/clubbar-backup/archives';

    public function test_a_dsn_names_the_user_the_host_and_the_folder(): void
    {
        $dsn = BackupDsn::parse(self::VALID);

        $this->assertInstanceOf(HiDriveDsn::class, $dsn);
        $this->assertSame('hidrive', $dsn->scheme);
        $this->assertSame('clubbar-backup', $dsn->username);
        $this->assertSame('webdav.hidrive.ionos.com', $dsn->host);
        $this->assertSame('users/clubbar-backup/archives', $dsn->path);
    }

    /**
     * The user name is half of a credential, and this string is logged on every
     * run, shown on the backups page and mailed. All three end up in
     * screenshots. The path usually contains it anyway for a home-directory
     * DSN, which is unavoidable — printing it *as the identity* is not.
     */
    public function test_the_description_names_the_folder_and_never_the_user(): void
    {
        $description = BackupDsn::parse(self::VALID)->describe();

        $this->assertSame('hidrive://webdav.hidrive.ionos.com/users/clubbar-backup/archives', $description);
        $this->assertStringNotContainsString('@', $description);
    }

    public function test_one_location_answers_the_upload_the_listing_and_the_delete(): void
    {
        $dsn = BackupDsn::parse(self::VALID);
        $this->assertInstanceOf(HiDriveDsn::class, $dsn);

        $this->assertSame(
            'https://webdav.hidrive.ionos.com/users/clubbar-backup/archives',
            $dsn->collectionUrl()
        );
        $this->assertSame(
            'https://webdav.hidrive.ionos.com/users/clubbar-backup/archives/clubbar-20260904-030000-1a2b3c4d.cbb',
            $dsn->urlFor('clubbar-20260904-030000-1a2b3c4d.cbb')
        );
        $this->assertSame(
            'users/clubbar-backup/archives/clubbar-20260904-030000-1a2b3c4d.cbb',
            $dsn->remotePathFor('clubbar-20260904-030000-1a2b3c4d.cbb')
        );
    }

    /**
     * Segment by segment, never the whole path at once.
     *
     * `rawurlencode()` over the lot escapes the separators too, which asks the
     * server for one ludicrously named file in the user's root rather than for
     * a file in a folder.
     */
    public function test_path_segments_are_encoded_without_eating_the_separators(): void
    {
        $dsn = BackupDsn::parse('hidrive://u@host.example/users/u/Club Bar/archives');
        $this->assertInstanceOf(HiDriveDsn::class, $dsn);

        $this->assertSame('https://host.example/users/u/Club%20Bar/archives', $dsn->collectionUrl());
    }

    /**
     * A DSN gets pasted into support threads and screenshots. One carrying a
     * password is refused rather than accepted, so the leak cannot happen by
     * ordinary helpfulness.
     */
    public function test_a_password_in_the_dsn_is_refused(): void
    {
        $this->expectException(BackupDsnException::class);
        $this->expectExceptionMessageMatches('/remote_secret/');

        BackupDsn::parse('hidrive://clubbar-backup:hunter2@webdav.hidrive.ionos.com/users/x/archives');
    }

    /**
     * @dataProvider malformed
     */
    public function test_a_malformed_dsn_names_the_part_that_is_missing(string $dsn, string $expected): void
    {
        try {
            BackupDsn::parse($dsn);
            $this->fail('Expected ' . $dsn . ' to be refused.');
        } catch (BackupDsnException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
            $this->assertStringContainsString(HiDriveDsn::SHAPE, $e->getMessage(),
                'the message must quote this scheme\'s shape, not the other one\'s');
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformed(): array
    {
        return [
            'no user' => ['hidrive://webdav.hidrive.ionos.com/users/x/archives', 'user name'],
            'empty user' => ['hidrive://@webdav.hidrive.ionos.com/users/x/archives', 'user name'],
            'no host' => ['hidrive://user@/users/x/archives', 'host'],
            // The root is the backup user's whole home. Writing archives there
            // would make the retention delete's blast radius the home directory,
            // which is why this is the one part the Graph DSN treats as optional
            // and this one does not.
            'no folder' => ['hidrive://user@webdav.hidrive.ionos.com', 'folder path'],
            'root only' => ['hidrive://user@webdav.hidrive.ionos.com/', 'folder path'],
        ];
    }
}
