<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Backups\Domain\BackupRetention;
use App\Modules\Backups\Services\BackupStatusCheck;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\BackupHealthMailBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * Rendering the backup warning at send time (#693, ADR-0038 rule 5).
 *
 * The queue row carries no content at all — `subject_id` is the installation and
 * `dedup_key` is a day and a recipient id — so everything the mail says is a
 * live reading of the backup directory, taken *here* rather than when the scan
 * queued the row.
 *
 * That gap is asymmetric, which is what makes it worth testing in both
 * directions: a problem that has grown worse should be reported as it now
 * stands, and one that has been fixed in the fifteen minutes since the scan
 * should not be reported as a fault at all.
 *
 * Part of #693, epic #686.
 */
class BackupHealthMailBuilderTest extends TestCase
{
    use TempTree;

    private const KEYS = 'admin:bb637d8ec1cb92bca0467e59faa6d61f6b7f8088103e5b89d7afdc01f1efa45c';

    private string $dir;
    private AdminUsersRepository $adminUsers;
    private MailConfigDto $mailConfig;

    protected function setUp(): void
    {
        $this->dir = self::makeTempTree('backup-health-builder');

        $adminUsers = $this->adminUsers = $this->createMock(AdminUsersRepository::class);
        $adminUsers->method('findById')->willReturnMap([
            ['admin-1', ['display_name' => 'Vorstand', 'email' => 'vorstand@example.org']],
            ['admin-2', ['display_name' => '', 'email' => 'noname@example.org']],
        ]);

        $this->mailConfig = MailConfigDto::fromRow([
            'sender_name' => 'FRGS Ruderbar',
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'FRGS Ruderbar',
        ]);
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->dir);
    }

    public function test_it_builds_only_its_own_kind(): void
    {
        $builder = $this->builder();

        $this->assertTrue($builder->supports(MailKind::BACKUP_HEALTH_WARNING));
        $this->assertFalse($builder->supports(MailKind::BACKUP_SECRET_EXPIRY_WARNING));
        $this->assertFalse($builder->supports(MailKind::CREDIT_LIMIT_DIGEST));
    }

    /**
     * **The state the queue row does not carry.** Nothing in the outbox says
     * *what* was wrong; it is read here, from the same class the self-check
     * page and the banner read.
     */
    public function test_it_reads_what_is_wrong_at_send_time(): void
    {
        $message = $this->builder()->build($this->row(), $this->mailConfig);

        $this->assertSame('vorstand@example.org', $message->to);
        $this->assertStringContainsString('backup is not working', $message->subject);
        $this->assertStringContainsString('never run', $message->text);
    }

    /**
     * A problem fixed between the scan and the drain is good news, not a
     * failure to render. Refusing here would leave a red row in the
     * Notifications page for a backup that started working again — the wrong
     * lesson to teach a reader about that page.
     */
    public function test_a_problem_that_cleared_before_the_drain_still_renders(): void
    {
        // A fresh archive: the never-ran row is gone and nothing else fails.
        file_put_contents(
            $this->dir . '/clubbar-' . gmdate('Ymd-His') . '-1a2b3c4d.cbb',
            'sealed'
        );

        $message = $this->builder()->build($this->row(), $this->mailConfig);

        $this->assertStringContainsString('cleared', strtolower($message->subject));
        $this->assertStringContainsString('nothing to do', $message->text);
    }

    /**
     * The address comes from the row's snapshot, never re-read from
     * `admin_users`: it records who was written to, and the address may have
     * moved between the enqueue and the drain.
     */
    public function test_the_address_is_the_rows_snapshot_not_the_current_one(): void
    {
        $message = $this->builder()->build(
            $this->row(['recipient' => 'moved-on@example.org']),
            $this->mailConfig
        );

        $this->assertSame('moved-on@example.org', $message->to);
    }

    /** An account with no display name is greeted without one rather than with an empty string. */
    public function test_an_admin_without_a_display_name_gets_no_name(): void
    {
        $message = $this->builder()->build(
            $this->row(['admin_user_id' => 'admin-2']),
            $this->mailConfig
        );

        $this->assertNull($message->toName);
    }

    /** Each admin in their own language, taken from the row. */
    public function test_it_renders_in_the_recipients_language(): void
    {
        $message = $this->builder()->build($this->row(['language' => 'de']), $this->mailConfig);

        $this->assertStringContainsString('Datensicherung', $message->subject);
    }

    // ---------------------------------------------------------------- helpers

    private function builder(): BackupHealthMailBuilder
    {
        return new BackupHealthMailBuilder(
            new BackupStatusCheck($this->dir, self::KEYS, BackupRetention::defaults()),
            $this->adminUsers,
        );
    }

    /**
     * A row as `claimBatch()` returns it.
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'kind' => MailKind::BACKUP_HEALTH_WARNING->value,
            // The installation itself — ADR-0049 decision 8 is why there is no
            // backup row to point at.
            'subject_id' => '1',
            'admin_user_id' => 'admin-1',
            'recipient' => 'vorstand@example.org',
            'language' => 'en',
            'dedup_key' => 'stale:2026-08-27:admin-1',
        ];
    }
}
