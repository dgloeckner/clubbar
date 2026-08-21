<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * Operational mail is addressed to an office, not to an account (#633,
 * ADR-0044).
 *
 * The unit tests pin the mapping and the fan-out's logic against doubles. This
 * runs the real notifier against the real tables, because the narrowing is a
 * `JOIN` on `admin_user_roles` — the one part of it that a mock cannot be wrong
 * about, and the part that decides who is actually written to.
 *
 * Three accounts, one per office, and the assertions are mostly negative: what
 * lands in the outbox for an account holding only `getraenkewart`. Before this
 * issue the answer was *everything* — the club's encryption-key fingerprint,
 * which tills had just been issued credentials, and who had been promoted,
 * every one of them behind an `admin`-only route in the panel.
 */
class AdminNoticeRolesTest extends DatabaseTestCase
{
    private AdminNotifier $notifier;

    /** @var list<string> */
    private array $createdAdmins = [];

    /** @var list<string> */
    private array $createdSubjects = [];

    private ?string $originalClubAddress = null;

    private string $adminAddress = '';
    private string $kassenwartAddress = '';
    private string $getraenkewartAddress = '';

    protected function setUp(): void
    {
        parent::setUp();

        $mailConfig = new MailConfigRepository($this->db, $this->logger);
        $this->originalClubAddress = $mailConfig->getConfig()['club_notification_address'] ?? null;
        // The club copy is a separate mechanism (ADR-0044 rule 3) with its own
        // suite. Unset here so every recipient this file sees is an account.
        $this->givenClubAddressIs(null);

        $this->notifier = new AdminNotifier(
            new MailOutboxRepository($this->db, $this->logger),
            new AdminUsersRepository($this->db, $this->logger),
            $this->createMock(AuditService::class),
            $mailConfig,
            $this->logger,
        );

        $this->adminAddress = $this->givenAdminHolding([AdminRole::ADMIN]);
        $this->kassenwartAddress = $this->givenAdminHolding([AdminRole::KASSENWART]);
        $this->getraenkewartAddress = $this->givenAdminHolding([AdminRole::GETRAENKEWART]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSubjects as $subjectId) {
            $this->db->prepare('DELETE FROM mail_outbox WHERE subject_id = ?')->execute([$subjectId]);
        }
        foreach ($this->createdAdmins as $id) {
            $this->db->prepare('DELETE FROM mail_outbox WHERE admin_user_id = ?')->execute([$id]);
            // `admin_user_roles` has ON DELETE CASCADE; the account is enough.
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }
        $this->createdSubjects = [];
        $this->createdAdmins = [];

        $this->givenClubAddressIs($this->originalClubAddress);

        parent::tearDown();
    }

    /**
     * A key's fingerprint travels in full, deliberately — it is the only value
     * distinguishing the club's key from someone else's. Which is exactly why
     * it goes only to the office that can open the key screen.
     */
    public function test_a_key_notice_reaches_the_admin_office_and_nobody_else(): void
    {
        $recipients = $this->warn(MailKind::ENCRYPTION_KEY_ACTIVATED);

        $this->assertContains($this->adminAddress, $recipients);
        $this->assertNotContains($this->kassenwartAddress, $recipients);
        $this->assertNotContains($this->getraenkewartAddress, $recipients);
    }

    /**
     * The one kind that is not `admin`-only, and it is not an exception: its
     * dashboard alert is `TREASURY` because ADR-0045 names the Kassenwart as
     * the recipient, and the mail mirrors that route rather than inventing a
     * second answer.
     */
    public function test_a_violation_notice_reaches_the_treasury_offices(): void
    {
        $recipients = $this->warn(MailKind::JUGENDSCHUTZ_VIOLATION);

        $this->assertContains($this->adminAddress, $recipients);
        $this->assertContains($this->kassenwartAddress, $recipients);
        $this->assertNotContains($this->getraenkewartAddress, $recipients);
    }

    /**
     * The acceptance criterion, over every kind rather than the two above: an
     * account that looks after the bar stock is written to by nothing at all.
     */
    public function test_the_getraenkewart_is_written_to_by_no_operational_kind(): void
    {
        foreach (MailKind::cases() as $kind) {
            if ($kind->addressesMember()) {
                continue;
            }

            $this->assertNotContains(
                $this->getraenkewartAddress,
                $this->warn($kind),
                $kind->value . ' must not reach an office that cannot open the screen it is about'
            );
        }
    }

    /**
     * The list the two mail builders resolve a display name through is the
     * unfiltered one, and stays that way: a row already names who it was
     * written to, and a filtered lookup would blank the greeting for an account
     * whose roles moved after it was queued.
     */
    public function test_the_unfiltered_list_still_resolves_every_active_account(): void
    {
        $addresses = array_column(
            (new AdminUsersRepository($this->db, $this->logger))->findActiveRecipients(),
            'email'
        );

        $this->assertContains($this->getraenkewartAddress, $addresses);
        $this->assertContains($this->kassenwartAddress, $addresses);
    }

    /**
     * Queue one notice of `$kind` about a subject nothing else is using, and
     * report who it was addressed to.
     *
     * @return list<string>
     */
    private function warn(MailKind $kind): array
    {
        $subjectId = $this->generateUuid();
        $this->createdSubjects[] = $subjectId;

        $this->notifier->warnAdmins($kind, $subjectId, 'roles-test');

        $stmt = $this->db->prepare(
            'SELECT recipient FROM mail_outbox WHERE kind = ? AND subject_id = ? ORDER BY recipient'
        );
        $stmt->execute([$kind->value, $subjectId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * An active account holding exactly `$roles`.
     *
     * Written straight to the tables rather than through `AdminUsersService`:
     * that service refuses a role set nobody may hold and demands an acting
     * admin, and this file is about who a notice reaches, not about how a grant
     * is made.
     *
     * @param list<AdminRole> $roles
     */
    private function givenAdminHolding(array $roles): string
    {
        $id = $this->generateUuid();
        $email = 'notice-roles-' . substr($id, 0, 8) . '@example.test';

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute([$id, $email, password_hash('not-used-' . $id, PASSWORD_DEFAULT), 'Notice Roles', 'de']);
        $this->createdAdmins[] = $id;

        foreach ($roles as $role) {
            $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)')
                ->execute([$id, $role->value]);
        }

        return $email;
    }

    private function givenClubAddressIs(?string $address): void
    {
        $this->db->prepare('UPDATE mail_config SET club_notification_address = ? WHERE id = 1')
            ->execute([$address]);
    }
}
