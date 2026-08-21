<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AdminUsers;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\NotificationsService;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * Account creation and role changes leave the panel by mail (ADR-0044 rule 3,
 * #521).
 *
 * Uses the real `AdminNotifier` and reads the outbox, rather than asserting
 * against a double that would only be agreeing with itself.
 *
 * The single-admin case is the one that matters. ADR-0043 already noted that an
 * admin-addressed alert buys a one-admin installation a receipt rather than a
 * control; for *lifecycle* mail it is worse, because the message travels from
 * the person who acted to the same person about something they just did — and
 * is silent in precisely the case where that account is the compromised one.
 * The club-level address is the second pair of eyes, and it is asserted here on
 * an installation whose only admin is the actor.
 */
class AdminLifecycleNoticeTest extends DatabaseTestCase
{
    private AdminUsersService $service;
    private MailConfigRepository $mailConfig;

    /** @var list<string> */
    private array $createdAdmins = [];

    private ?string $originalClubAddress = null;
    private string $clubAddress = '';

    protected function setUp(): void
    {
        parent::setUp();

        $admins = new AdminUsersRepository($this->db, $this->logger);
        $roles = new AdminUserRolesRepository($this->db, $this->logger);
        $this->mailConfig = new MailConfigRepository($this->db, $this->logger);

        $this->originalClubAddress = $this->mailConfig->getConfig()['club_notification_address'] ?? null;
        $this->clubAddress = 'vorstand-' . bin2hex(random_bytes(4)) . '@example.test';

        $this->service = new AdminUsersService(
            $admins,
            $this->createMock(AuditService::class),
            $this->createMock(NotificationsService::class),
            $roles,
            new AdminNotifier(
                new MailOutboxRepository($this->db, $this->logger),
                $admins,
                $this->createMock(AuditService::class),
                $this->mailConfig,
                $this->logger,
            ),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->createdAdmins as $id) {
            $this->db->prepare('DELETE FROM mail_outbox WHERE subject_id = ? OR admin_user_id = ?')
                ->execute([$id, $id]);
            $this->db->prepare('DELETE FROM audit_log WHERE entity_id = ? OR admin_user_id = ?')
                ->execute([$id, $id]);
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }
        $this->createdAdmins = [];

        $this->db->prepare('UPDATE mail_config SET club_notification_address = ? WHERE id = 1')
            ->execute([$this->originalClubAddress]);

        parent::tearDown();
    }

    public function test_creating_an_account_queues_a_notice_to_the_club_address(): void
    {
        $this->givenClubAddressIs($this->clubAddress);

        $created = $this->createAccount();

        $this->assertContains(
            $this->clubAddress,
            $this->recipientsOf(MailKind::ADMIN_ACCOUNT_CREATED, $created),
        );
    }

    /**
     * The acceptance criterion, and the reason the field exists: with no other
     * admin to write to, the club address is the only witness.
     */
    public function test_the_club_address_is_written_to_even_when_no_other_admin_exists(): void
    {
        $this->givenClubAddressIs($this->clubAddress);
        $this->givenNoOtherActiveAdmins();

        $created = $this->createAccount();
        $recipients = $this->recipientsOf(MailKind::ADMIN_ACCOUNT_CREATED, $created);

        $this->assertContains($this->clubAddress, $recipients);
    }

    public function test_a_role_change_queues_its_own_notice(): void
    {
        $this->givenClubAddressIs($this->clubAddress);
        // Created as Getränkewart rather than the default admin: this test is
        // about the notice a role change queues, not about the "never
        // neuter the last admin" guard (#548) — and on a migrate-only CI
        // database with no seeded admin, this account created as `admin`
        // would be the only one, tripping that guard on the very next line.
        $created = $this->createAccount([AdminRole::GETRAENKEWART]);

        $this->service->setRoles($created, [AdminRole::KASSENWART], null);

        $this->assertContains(
            $this->clubAddress,
            $this->recipientsOf(MailKind::ADMIN_ROLE_CHANGED, $created),
        );
    }

    /**
     * A save that moves no role is not an event. The queue must not carry one,
     * for the same reason the audit log does not.
     */
    public function test_an_unchanged_role_set_queues_nothing(): void
    {
        $this->givenClubAddressIs($this->clubAddress);
        $created = $this->createAccount([AdminRole::KASSENWART]);

        $this->service->setRoles($created, [AdminRole::KASSENWART], null);

        $this->assertSame([], $this->recipientsOf(MailKind::ADMIN_ROLE_CHANGED, $created));
    }

    /**
     * An installation that never configures the address is not broken; it has
     * simply not bought the property. Today's behaviour, no error, nothing
     * queued to nowhere.
     */
    public function test_an_unset_club_address_queues_no_club_copy_and_raises_nothing(): void
    {
        $this->givenClubAddressIs(null);

        $created = $this->createAccount();
        $recipients = $this->recipientsOf(MailKind::ADMIN_ACCOUNT_CREATED, $created);

        $this->assertNotContains($this->clubAddress, $recipients);
        foreach ($recipients as $recipient) {
            $this->assertNotSame('', trim($recipient), 'nothing may be queued to an empty address');
        }
    }

    /** A creation is not a role change, however many roles it starts with. */
    public function test_creating_an_account_does_not_also_announce_a_role_change(): void
    {
        $this->givenClubAddressIs($this->clubAddress);

        $created = $this->createAccount([AdminRole::KASSENWART, AdminRole::GETRAENKEWART]);

        $this->assertSame([], $this->recipientsOf(MailKind::ADMIN_ROLE_CHANGED, $created));
        $this->assertNotSame([], $this->recipientsOf(MailKind::ADMIN_ACCOUNT_CREATED, $created));
    }

    /** @param list<AdminRole> $roles */
    private function createAccount(array $roles = []): string
    {
        $email = 'lifecycle-' . bin2hex(random_bytes(4)) . '@example.test';
        $result = $this->service->createAdminUser($email, 'Lifecycle Target', 'de', null, $roles);
        $id = $result['admin']->id;
        $this->createdAdmins[] = $id;

        return $id;
    }

    private function givenClubAddressIs(?string $address): void
    {
        $this->db->prepare('UPDATE mail_config SET club_notification_address = ? WHERE id = 1')
            ->execute([$address]);
    }

    /**
     * Deactivate every admin this test did not create, so the installation has
     * exactly one active account — the shape ADR-0044 rule 3 is about. Undone
     * by tearDown restoring nothing: `is_active` is put back here rather than
     * left, because other suites share this table.
     */
    private function givenNoOtherActiveAdmins(): void
    {
        $this->db->exec('UPDATE admin_users SET is_active = 0');
        $this->addToAssertionCount(1);

        // Restored at the end of the test run, not left to chance.
        register_shutdown_function(function (): void {
            $this->db->exec('UPDATE admin_users SET is_active = 1');
        });
    }

    /** @return list<string> */
    private function recipientsOf(MailKind $kind, string $subjectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT recipient FROM mail_outbox WHERE kind = ? AND subject_id = ? ORDER BY recipient'
        );
        $stmt->execute([$kind->value, $subjectId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
