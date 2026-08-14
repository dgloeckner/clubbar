<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

/**
 * The outbox invariants the *database* enforces (ADR-0038, #402).
 *
 * The acceptance criterion behind this file is "finalize retry does not
 * duplicate emails", and ADR-0038 places that guarantee on a UNIQUE index
 * rather than in application code precisely so it cannot be lost to a
 * lookup-then-insert race. A test that mocked the repository would prove
 * nothing about it.
 */
class MailOutboxSchemaTest extends SchemaTestCase
{
    public function test_the_same_message_cannot_be_queued_twice_for_one_member(): void
    {
        $adminId = $this->createAdminUser();
        $memberId = $this->createMember();
        $settlementId = $this->insertSettlement($adminId);

        $this->insertOutbox($settlementId, $memberId);

        $this->assertDatabaseRefuses(
            fn () => $this->insertOutbox($settlementId, $memberId),
            'a second announcement for the same member of the same settlement is the duplicate email #361 forbids'
        );
    }

    /**
     * The key is (kind, subject_id, dedup_key), and `kind` is in it for a
     * reason: a member who was announced to and then had the collection called
     * off needs both messages about the same settlement.
     */
    public function test_a_cancellation_notice_may_join_the_announcement_it_retracts(): void
    {
        $adminId = $this->createAdminUser();
        $memberId = $this->createMember();
        $settlementId = $this->insertSettlement($adminId);

        $this->insertOutbox($settlementId, $memberId, ['kind' => 'sepa_prenotification', 'status' => 'sent']);
        $this->insertOutbox($settlementId, $memberId, ['kind' => 'cancellation_notice']);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mail_outbox WHERE subject_id = ?');
        $stmt->execute([$settlementId]);

        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    public function test_two_members_of_one_settlement_each_get_their_own_row(): void
    {
        $adminId = $this->createAdminUser();
        $settlementId = $this->insertSettlement($adminId);

        $this->insertOutbox($settlementId, $this->createMember('One'));
        $this->insertOutbox($settlementId, $this->createMember('Two'));

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mail_outbox WHERE subject_id = ?');
        $stmt->execute([$settlementId]);

        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    /**
     * ADR-0038 rule 5. The absence of this column is the decision: content is
     * rendered from the (append-only) settlement at send time, and storing
     * bodies would multiply the copies of member data for no evidentiary gain.
     */
    public function test_the_outbox_stores_no_message_body(): void
    {
        $columns = $this->db->query('SHOW COLUMNS FROM mail_outbox')->fetchAll(\PDO::FETCH_COLUMN);

        foreach (['body', 'body_html', 'body_text', 'html', 'subject'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "mail_outbox must not store rendered content (ADR-0038 rule 5), found '{$forbidden}'"
            );
        }

        // What it does store is the recipient — the one fact that is not
        // reproducible later, and the proof of who was announced to.
        $this->assertContains('recipient', $columns);
    }

    public function test_a_queued_message_starts_pending_and_unclaimed(): void
    {
        $adminId = $this->createAdminUser();
        $memberId = $this->createMember();
        $settlementId = $this->insertSettlement($adminId);

        $id = $this->insertOutbox($settlementId, $memberId);

        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['claim_token']);
        $this->assertNull($row['sent_at']);
        $this->assertNotNull($row['queued_at']);
        // Defaulted rather than null so the drain's claim predicate stays a
        // plain comparison.
        $this->assertNotNull($row['next_attempt_at']);
    }

    public function test_the_heartbeat_singleton_starts_with_no_observed_run(): void
    {
        $row = $this->db->query('SELECT * FROM cron_heartbeat WHERE id = 1')->fetch();

        $this->assertNotFalse($row, 'the singleton has to exist from the migration, or #405 cannot tell '
            . '"no run observed" apart from "table missing"');
        $this->assertArrayHasKey('last_run_at', $row);
        $this->assertArrayHasKey('php_version', $row);
    }

    /**
     * Deleting a member takes their queued messages with them.
     *
     * This is the cascade that matters. `subject_id` is polymorphic and
     * therefore carries no foreign key, so a settlement is *not* what cleans
     * the queue up — `member_id` is, and it is also how erasure (#408) finds
     * the addresses this table holds. A member's mail can never outlive the
     * member.
     */
    public function test_deleting_a_member_takes_their_queued_messages_with_them(): void
    {
        $adminId = $this->createAdminUser();
        $memberId = $this->createMember();
        $settlementId = $this->insertSettlement($adminId);
        $this->insertOutbox($settlementId, $memberId);

        // Only the member is deleted; the cascade is what has to remove the
        // outbox row. Its FK dependants go first so the delete can happen at all.
        $this->db->prepare('DELETE FROM settlement_items WHERE member_id = ?')->execute([$memberId]);
        $this->db->prepare('DELETE FROM transactions WHERE member_id = ?')->execute([$memberId]);
        $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$memberId]);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mail_outbox WHERE subject_id = ?');
        $stmt->execute([$settlementId]);

        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    /**
     * The generalisation #438 needs: a warning about an encryption key,
     * addressed to an admin, repeated per tier.
     *
     * Nothing enqueues these yet — this asserts that the *table* takes them, so
     * that adding the feature is code rather than another migration.
     */
    public function test_the_queue_takes_an_admin_addressed_warning_about_a_credential(): void
    {
        $adminId = $this->createAdminUser();
        $keyId = $this->generateUuid();

        $this->insertOutbox($keyId, null, [
            'kind' => 'key_expiry_warning',
            'dedup_key' => '90d:' . $adminId,
            'admin_user_id' => $adminId,
            'recipient' => 'admin@example.com',
        ]);

        // A second tier about the same key is a different message.
        $this->insertOutbox($keyId, null, [
            'kind' => 'key_expiry_warning',
            'dedup_key' => '30d:' . $adminId,
            'admin_user_id' => $adminId,
            'recipient' => 'admin@example.com',
        ]);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mail_outbox WHERE subject_id = ?');
        $stmt->execute([$keyId]);
        $this->assertSame(2, (int) $stmt->fetchColumn());

        // The same tier twice is not.
        $this->assertDatabaseRefuses(
            fn () => $this->insertOutbox($keyId, null, [
                'kind' => 'key_expiry_warning',
                'dedup_key' => '30d:' . $adminId,
                'admin_user_id' => $adminId,
                'recipient' => 'admin@example.com',
            ]),
            'a tier that has already been warned about must not fire again — this is the '
            . 'idempotent-notification storage #438 asks for'
        );
    }

    /** @param array<string, mixed> $overrides */
    private function insertOutbox(string $subjectId, ?string $memberId, array $overrides = []): string
    {
        $row = array_merge([
            'id' => $this->generateUuid(),
            'kind' => 'sepa_prenotification',
            'subject_id' => $subjectId,
            'dedup_key' => (string) $memberId,
            'member_id' => $memberId,
            'recipient' => $memberId . '@example.com',
            'language' => 'de',
        ], $overrides);

        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $this->db->prepare("INSERT INTO mail_outbox ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_values($row));

        return $row['id'];
    }

    /** @param array<string, mixed> $overrides */
    private function insertSettlement(string $adminId, array $overrides = []): string
    {
        $row = array_merge([
            'id' => $this->generateUuid(),
            'settlement_date' => '2026-08-01',
            'execution_date' => '2026-08-21',
            'total_amount_cents' => 350,
            'member_count' => 1,
            'created_by_admin_id' => $adminId,
        ], $overrides);

        $this->track('settlements', $row['id']);

        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $this->db->prepare("INSERT INTO settlements ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_values($row));

        return $row['id'];
    }
}
