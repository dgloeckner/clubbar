<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\Services\MembersService;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Repositories\SettlementAnnouncementsRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * Which member lifecycle notice a record change produces (ADR-0051).
 *
 * Everything interesting about this feature is the decision, not the message:
 * *is this an onboarding or a replacement, and has this member ever heard from
 * the club at all?* So this goes through the **real**
 * `MembersService::createMember()` / `::updateMember()` and asserts by reading
 * `mail_outbox` afterwards.
 *
 * The invariant under test throughout: **the welcome is the first message a
 * member ever receives.** Nothing member-addressed may be queued before it.
 */
class MemberLifecycleMailTest extends DatabaseTestCase
{
    private MembersService $service;

    /** @var list<string> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureActiveEncryptionKey();

        $membersRepository = new MembersRepository(
            $this->db,
            $this->logger,
            new IbanSealedBox(str_repeat('0', 63) . '2', 'test'),
            new EncryptionKeysRepository($this->db, $this->logger),
        );
        $auditService = new AuditService(new AuditLogRepository($this->db, $this->logger));

        $this->service = new MembersService(
            $membersRepository,
            new TransactionsRepository($this->db, $this->logger),
            $auditService,
            new AuditLogRepository($this->db, $this->logger),
            new NotificationsService(
                new MailOutboxRepository($this->db, $this->logger),
                $membersRepository,
                $auditService,
                new AdminUsersRepository($this->db, $this->logger),
                new SettlementAnnouncementsRepository($this->db, $this->logger),
                $this->logger,
            ),
            $this->db,
            null,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->memberIds as $id) {
            $this->db->prepare('DELETE FROM mail_outbox WHERE member_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM audit_log WHERE entity_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
        }

        parent::tearDown();
    }

    /** A card UID that is unique to this run — the column carries a UNIQUE index. */
    private function cardUid(string $salt = ''): string
    {
        return strtoupper(substr(md5(uniqid('card' . $salt, true)), 0, 14));
    }

    private function create(?string $cardUid, ?string $email = null): string
    {
        $member = $this->service->createMember(
            firstName: 'Lifecycle',
            lastName: 'Test',
            email: $email ?? ('m' . bin2hex(random_bytes(6)) . '@example.com'),
            cardUid: $cardUid,
            language: SupportedLanguage::German,
        );

        $this->memberIds[] = $member->id;

        return $member->id;
    }

    /**
     * Kinds queued for a member, sorted.
     *
     * Deliberately **not** in insert order. `queued_at` has second granularity
     * and everything one request queues lands inside the same second, so the
     * tiebreak is a random UUID — an order assertion here would pass or fail on
     * the roll of a primary key. Send order is the drain's business and no
     * caller of this feature depends on it.
     *
     * @return list<string>
     */
    private function queuedKinds(string $memberId): array
    {
        $stmt = $this->db->prepare('SELECT kind FROM mail_outbox WHERE member_id = ?');
        $stmt->execute([$memberId]);

        $kinds = array_map(static fn(array $r) => (string) $r['kind'], $stmt->fetchAll());
        sort($kinds);

        return $kinds;
    }

    /**
     * Which address each queued kind was snapshotted against.
     *
     * A map rather than a list, for the reason above — what matters is that the
     * notice about *losing* an address went to the old one, not which row the
     * database happened to hand back first.
     *
     * @return array<string,string>
     */
    private function recipientsByKind(string $memberId): array
    {
        $stmt = $this->db->prepare('SELECT kind, recipient FROM mail_outbox WHERE member_id = ?');
        $stmt->execute([$memberId]);

        $byKind = [];
        foreach ($stmt->fetchAll() as $row) {
            $byKind[(string) $row['kind']] = (string) $row['recipient'];
        }

        return $byKind;
    }

    /**
     * @param list<string> $kinds
     * @return list<string>
     */
    private static function sorted(array $kinds): array
    {
        sort($kinds);

        return $kinds;
    }

    // ------------------------------------------------------------------
    // The card decides when a member is greeted
    // ------------------------------------------------------------------

    /**
     * The ordinary onboarding under ADR-0021: the record is created, the UID is
     * typed in afterwards. Creating the record must say nothing — a member row
     * with no card is paperwork, and there is nothing yet to welcome them to.
     */
    public function test_creating_a_member_without_a_card_queues_nothing(): void
    {
        $memberId = $this->create(null);

        $this->assertSame([], $this->queuedKinds($memberId));
    }

    public function test_assigning_the_first_card_welcomes_the_member(): void
    {
        $memberId = $this->create(null);

        $this->service->updateMember($memberId, ['card_uid' => $this->cardUid()]);

        $this->assertSame([MailKind::MEMBER_WELCOME->value], $this->queuedKinds($memberId));
    }

    /** Created with a card already on it: onboarded in one step, welcomed here. */
    public function test_creating_a_member_with_a_card_welcomes_them_immediately(): void
    {
        $memberId = $this->create($this->cardUid());

        $this->assertSame([MailKind::MEMBER_WELCOME->value], $this->queuedKinds($memberId));
    }

    /**
     * The replacement is read from the transition — a card that replaces
     * another is a replacement — so this holds whatever the queue contains, and
     * cannot be broken by a welcome being pruned at ninety days.
     */
    public function test_a_second_card_is_a_replacement_and_not_a_second_welcome(): void
    {
        $memberId = $this->create($this->cardUid('a'));

        $this->service->updateMember($memberId, ['card_uid' => $this->cardUid('b')]);

        $this->assertSame(
            self::sorted([MailKind::MEMBER_WELCOME->value, MailKind::MEMBER_CARD_REPLACED->value]),
            $this->queuedKinds($memberId),
        );
    }

    public function test_clearing_a_card_says_nothing(): void
    {
        $memberId = $this->create($this->cardUid());

        $this->service->updateMember($memberId, ['card_uid' => null]);

        $this->assertSame([MailKind::MEMBER_WELCOME->value], $this->queuedKinds($memberId));
    }

    /**
     * The one case the transition cannot answer: cleared and reassigned looks
     * exactly like a first assignment. The refused welcome insert is what
     * reports that this member has been greeted already — the unique index
     * answering, not a lookup.
     */
    public function test_a_card_reassigned_after_being_cleared_is_a_replacement(): void
    {
        $memberId = $this->create($this->cardUid('a'));
        $this->service->updateMember($memberId, ['card_uid' => null]);

        $this->service->updateMember($memberId, ['card_uid' => $this->cardUid('c')]);

        $this->assertSame(
            self::sorted([MailKind::MEMBER_WELCOME->value, MailKind::MEMBER_CARD_REPLACED->value]),
            $this->queuedKinds($memberId),
        );
    }

    /** Re-saving the same card is not an assignment and must not mail anybody. */
    public function test_saving_the_same_card_again_queues_nothing_new(): void
    {
        $card = $this->cardUid();
        $memberId = $this->create($card);

        $this->service->updateMember($memberId, ['card_uid' => $card, 'first_name' => 'Magdalena']);

        $this->assertSame([MailKind::MEMBER_WELCOME->value], $this->queuedKinds($memberId));
    }

    // ------------------------------------------------------------------
    // An address change is told at both ends — once the member has a card
    // ------------------------------------------------------------------

    public function test_an_address_change_writes_to_both_the_old_and_the_new_address(): void
    {
        $memberId = $this->create($this->cardUid(), 'before@example.com');

        $this->service->updateMember($memberId, ['email' => 'after@example.com']);

        $this->assertSame(
            self::sorted([
                MailKind::MEMBER_WELCOME->value,
                MailKind::MEMBER_EMAIL_CHANGED->value,
                MailKind::MEMBER_EMAIL_ACTIVATED->value,
            ]),
            $this->queuedKinds($memberId),
            'welcome, then one notice at each end of the move',
        );

        // The claim that matters: the notice about losing an address is
        // snapshotted against the address being lost, which `members.email` no
        // longer holds.
        // assertEquals, not assertSame: this is a map, and the row order the
        // database returns is the same coin-toss the kind list above avoids.
        $this->assertEquals([
            MailKind::MEMBER_WELCOME->value => 'before@example.com',
            MailKind::MEMBER_EMAIL_CHANGED->value => 'before@example.com',
            MailKind::MEMBER_EMAIL_ACTIVATED->value => 'after@example.com',
        ], $this->recipientsByKind($memberId));
    }

    /**
     * The gate the whole feature rests on. A member with no card has never
     * heard from the club, so an "your address was changed" notice would arrive
     * from a sender they do not recognise, about a relationship they do not yet
     * have.
     */
    public function test_an_address_change_for_a_member_with_no_card_says_nothing(): void
    {
        $memberId = $this->create(null, 'before@example.com');

        $this->service->updateMember($memberId, ['email' => 'after@example.com']);

        $this->assertSame([], $this->queuedKinds($memberId));
    }

    /**
     * A first card and a new address in one request is an onboarding, not a
     * move: there was no prior relationship for an address to move within, and
     * the welcome goes to the new address anyway.
     */
    public function test_a_first_card_and_a_new_address_together_send_only_the_welcome(): void
    {
        $memberId = $this->create(null, 'before@example.com');

        $this->service->updateMember($memberId, [
            'card_uid' => $this->cardUid(),
            'email' => 'after@example.com',
        ]);

        $this->assertSame([MailKind::MEMBER_WELCOME->value], $this->queuedKinds($memberId));
        $this->assertSame(
            'after@example.com',
            $this->recipientsByKind($memberId)[MailKind::MEMBER_WELCOME->value],
            'the welcome goes to the address the member will actually be reached at',
        );
    }

    /** A replacement *and* a move is both things happening, and both are told. */
    public function test_a_replacement_card_and_a_new_address_together_send_all_three(): void
    {
        $memberId = $this->create($this->cardUid('a'), 'before@example.com');

        $this->service->updateMember($memberId, [
            'card_uid' => $this->cardUid('b'),
            'email' => 'after@example.com',
        ]);

        $this->assertSame(self::sorted([
            MailKind::MEMBER_WELCOME->value,
            MailKind::MEMBER_CARD_REPLACED->value,
            MailKind::MEMBER_EMAIL_CHANGED->value,
            MailKind::MEMBER_EMAIL_ACTIVATED->value,
        ]), $this->queuedKinds($memberId));
    }

    /** Mirrors the `strcasecmp` guard on an admin's login address. */
    public function test_a_case_only_change_is_not_a_change_of_address(): void
    {
        $memberId = $this->create($this->cardUid(), 'Anna@Example.com');

        $this->service->updateMember($memberId, ['email' => 'anna@example.com']);

        $this->assertSame([MailKind::MEMBER_WELCOME->value], $this->queuedKinds($memberId));
    }

    /**
     * #362 permits clearing an address once the member is inactive. There is no
     * new address to announce and no farewell to send — only a move *to* a new
     * address notifies.
     */
    public function test_clearing_the_address_of_an_inactive_member_says_nothing(): void
    {
        $memberId = $this->create($this->cardUid(), 'before@example.com');

        $this->service->updateMember($memberId, ['is_active' => false, 'email' => null]);

        $this->assertSame([MailKind::MEMBER_WELCOME->value], $this->queuedKinds($memberId));
    }

    /**
     * A move is a move, in both directions. The occasion is a moment rather
     * than a tier precisely so a return to a previously used address is still
     * announced.
     */
    public function test_moving_back_to_a_previous_address_is_announced_again(): void
    {
        $memberId = $this->create($this->cardUid(), 'first@example.com');
        $this->service->updateMember($memberId, ['email' => 'second@example.com']);

        // A second within the same clock second would collide on the occasion.
        sleep(1);
        $this->service->updateMember($memberId, ['email' => 'first@example.com']);

        $kinds = $this->queuedKinds($memberId);
        $this->assertSame(2, count(array_filter(
            $kinds,
            static fn(string $k) => $k === MailKind::MEMBER_EMAIL_CHANGED->value,
        )), 'both moves are announced to the address being left');
    }

    // ------------------------------------------------------------------
    // Nothing here may break the write it describes
    // ------------------------------------------------------------------

    /**
     * Legacy members predating #362 may hold no address at all. A card
     * assignment must succeed regardless — the notice is best effort and the
     * assignment is not.
     */
    public function test_a_member_with_no_address_can_still_be_given_a_card(): void
    {
        $memberId = $this->create(null, 'legacy@example.com');
        $this->db->prepare('UPDATE members SET email = NULL, is_active = 0 WHERE id = ?')->execute([$memberId]);

        $updated = $this->service->updateMember($memberId, ['card_uid' => $this->cardUid()]);

        $this->assertNotNull($updated->cardUid);
        $this->assertSame([], $this->queuedKinds($memberId), 'nothing to send, and nothing pretended');
    }

    /**
     * Erasure writes an `ANON-` placeholder into `card_uid` to keep the UNIQUE
     * index satisfied. It runs through `MembersRepository::anonymize()` rather
     * than `updateMember()`, so it never reaches the trigger — but a placeholder
     * read as a card would turn an erasure into an onboarding, which is not a
     * mistake worth leaving one refactor away.
     */
    public function test_anonymising_a_member_queues_no_lifecycle_notice(): void
    {
        $memberId = $this->create(null, 'erase@example.com');

        $this->service->anonymizeMember($memberId);

        $this->assertSame([], $this->queuedKinds($memberId));
    }
}
