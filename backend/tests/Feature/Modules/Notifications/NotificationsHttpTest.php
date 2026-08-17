<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use Tests\Feature\HttpTestCase;

/**
 * The mail queue over the real stack — routes, session auth, filters, and the
 * one state change the UI is allowed to make (#407, ADR-0038 rule 4).
 *
 * Filtered by this class's own subject id throughout. The queue is global by
 * nature and the API suite leaves rows in it, so an assertion on "the first row
 * returned" would pass or fail depending on what else ran today (Pattern 003).
 */
class NotificationsHttpTest extends HttpTestCase
{
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';

    private string $adminId;
    private string $settlementId;
    private string $csrfToken;
    private MailOutboxRepository $outbox;

    /** @var list<string> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = new MailOutboxRepository($this->db, $this->createMock(\App\Shared\Logging\Logger::class));

        $this->adminId = $this->generateUuid();
        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$this->adminId, "queue-{$this->adminId}@example.test", self::PASSWORD_HASH, 'Queue Admin', 'de']);
        $this->grantRoles($this->adminId);

        $this->settlementId = $this->generateUuid();
        $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->settlementId, '2026-08-01', '2026-08-21', 1200, 2, $this->adminId]);

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['admin_user_id'] = $this->adminId;
        \App\Modules\Auth\Domain\SessionTimeout::begin($_SESSION);
        $this->csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $this->csrfToken;
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM mail_outbox WHERE subject_id = ?')->execute([$this->settlementId]);
        $this->db->prepare('DELETE FROM audit_log WHERE admin_user_id = ?')->execute([$this->adminId]);
        $this->db->prepare('DELETE FROM settlements WHERE id = ?')->execute([$this->settlementId]);
        foreach ($this->memberIds as $id) {
            $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
        }
        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$this->adminId]);

        parent::tearDown();
    }

    /** HttpTestCase has no UUID helper of its own; MailConfigHttpTest carries the same one. */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function member(string $first = 'Erika', string $last = 'Mustermann'): string
    {
        $id = $this->generateUuid();
        $this->memberIds[] = $id;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, $first, $last, $id . '@example.com', 'de']);

        return $id;
    }

    private function queue(MailKind $kind = MailKind::SEPA_PRENOTIFICATION, ?string $memberId = null): string
    {
        $memberId ??= $this->member();

        $this->outbox->enqueue(MailRequestDto::forMember(
            kind: $kind,
            settlementId: $this->settlementId,
            memberId: $memberId,
            recipient: $memberId . '@example.com',
            language: MailLanguage::German,
        ));

        $stmt = $this->db->prepare('SELECT id FROM mail_outbox WHERE subject_id = ? AND member_id = ? AND kind = ?');
        $stmt->execute([$this->settlementId, $memberId, $kind->value]);

        return (string) $stmt->fetchColumn();
    }

    private function markFailed(string $outboxId, string $error = '550 no such mailbox'): void
    {
        $this->db->prepare('UPDATE mail_outbox SET status = ?, attempts = 4, last_error = ? WHERE id = ?')
            ->execute([MailStatus::FAILED->value, $error, $outboxId]);
    }

    /** @return list<array<string,mixed>> This settlement's rows from a list response. */
    private function rowsOf(array $body): array
    {
        return array_values(array_filter(
            $body['data'],
            fn (array $row): bool => $row['subject_id'] === $this->settlementId
        ));
    }

    // ------------------------------------------------------------------
    // Reading the queue
    // ------------------------------------------------------------------

    public function test_it_lists_queued_messages_with_the_member_name_joined(): void
    {
        $this->queue();

        $response = $this->request('GET', '/api/admin/notifications?subject_id=' . $this->settlementId);
        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $rows = $this->rowsOf($body);
        $this->assertCount(1, $rows);

        // The name is what somebody scans; an id would send them back to the
        // member list to find out whose announcement this is.
        $this->assertSame('Erika Mustermann', $rows[0]['member_name']);
        $this->assertSame(MailKind::SEPA_PRENOTIFICATION->value, $rows[0]['kind']);
        $this->assertSame(MailStatus::PENDING->value, $rows[0]['status']);
        $this->assertArrayHasKey('pagination', $body);
    }

    /** The dropdowns come from the server that enforces the filter. */
    public function test_the_response_carries_the_filter_vocabulary(): void
    {
        $body = $this->decode($this->request('GET', '/api/admin/notifications'));

        $this->assertContains(MailKind::SEPA_PRENOTIFICATION->value, $body['filters']['kinds']);
        $this->assertContains(MailStatus::FAILED->value, $body['filters']['statuses']);
        $this->assertContains('queued_at', $body['filters']['sortable']);
    }

    public function test_filtering_by_kind_returns_only_that_kind(): void
    {
        $member = $this->member();
        $this->queue(MailKind::SEPA_PRENOTIFICATION, $member);
        $this->queue(MailKind::CANCELLATION_NOTICE, $member);

        $body = $this->decode($this->request(
            'GET',
            '/api/admin/notifications?subject_id=' . $this->settlementId . '&kind=' . MailKind::CANCELLATION_NOTICE->value
        ));

        $rows = $this->rowsOf($body);
        $this->assertCount(1, $rows);
        $this->assertSame(MailKind::CANCELLATION_NOTICE->value, $rows[0]['kind']);
    }

    public function test_filtering_by_status_returns_only_that_status(): void
    {
        $this->queue();
        $failed = $this->queue();
        $this->markFailed($failed);

        $body = $this->decode($this->request(
            'GET',
            '/api/admin/notifications?subject_id=' . $this->settlementId . '&status=' . MailStatus::FAILED->value
        ));

        $rows = $this->rowsOf($body);
        $this->assertCount(1, $rows);
        $this->assertSame($failed, $rows[0]['id']);
        $this->assertSame('550 no such mailbox', $rows[0]['last_error']);
    }

    public function test_searching_matches_the_recipient_address_and_the_member_name(): void
    {
        $member = $this->member('Wilhelmine', 'Sonderzeichen');
        $this->queue(MailKind::SEPA_PRENOTIFICATION, $member);

        foreach (['Wilhelmine', 'Sonderzeichen', substr($member, 0, 8)] as $term) {
            $body = $this->decode($this->request(
                'GET',
                '/api/admin/notifications?subject_id=' . $this->settlementId . '&search=' . urlencode($term)
            ));

            $this->assertCount(1, $this->rowsOf($body), "search for '{$term}' should have found the row");
        }
    }

    /**
     * Silently dropping an unknown filter would answer a question nobody asked
     * — the whole queue, presented as if it were the failures — and the caller
     * cannot tell that from an empty result.
     */
    public function test_an_unknown_kind_is_refused_rather_than_ignored(): void
    {
        $response = $this->request('GET', '/api/admin/notifications?kind=carrier_pigeon');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('kind', $this->decode($response)['messages']);
    }

    public function test_an_unknown_status_is_refused_rather_than_ignored(): void
    {
        $response = $this->request('GET', '/api/admin/notifications?status=probably_fine');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('status', $this->decode($response)['messages']);
    }

    public function test_reading_the_queue_requires_a_session(): void
    {
        $_SESSION = [];

        $this->assertSame(401, $this->request('GET', '/api/admin/notifications')->getStatusCode());
    }

    // ------------------------------------------------------------------
    // The one state change
    // ------------------------------------------------------------------

    public function test_retrying_a_failed_message_puts_it_back_in_the_queue(): void
    {
        $id = $this->queue();
        $this->markFailed($id);

        $response = $this->request('POST', "/api/admin/notifications/{$id}/retry", [], headers: ['X-CSRF-Token' => $this->csrfToken]);
        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame(MailStatus::PENDING->value, $body['status']);
        $this->assertSame(0, $body['attempts']);
        $this->assertNull($body['last_error']);

        // Asserted against the database, not the response: a body that echoes
        // the intended state is not evidence the row changed.
        $stmt = $this->db->prepare('SELECT status, attempts, last_error FROM mail_outbox WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $this->assertSame(MailStatus::PENDING->value, $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['last_error']);
    }

    public function test_a_retry_is_audited_against_the_settlement(): void
    {
        $id = $this->queue();
        $this->markFailed($id);

        $this->request('POST', "/api/admin/notifications/{$id}/retry", [], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE admin_user_id = ? AND action = 'mail_retried' AND entity_id = ?"
        );
        $stmt->execute([$this->adminId, $this->settlementId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * Resending an announcement somebody already received is not a retry, and
     * the refusal has to say which of the several reasons applies.
     */
    public function test_a_sent_message_cannot_be_retried(): void
    {
        $id = $this->queue();
        $this->db->prepare('UPDATE mail_outbox SET status = ?, sent_at = NOW() WHERE id = ?')
            ->execute([MailStatus::SENT->value, $id]);

        $response = $this->request('POST', "/api/admin/notifications/{$id}/retry", [], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('not_retryable', $body['error']);
        $this->assertStringContainsString('sent', $body['message']);
    }

    public function test_a_superseded_message_cannot_be_retried(): void
    {
        $id = $this->queue();
        $this->db->prepare('UPDATE mail_outbox SET status = ? WHERE id = ?')
            ->execute([MailStatus::SUPERSEDED->value, $id]);

        $response = $this->request('POST', "/api/admin/notifications/{$id}/retry", [], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_retrying_an_unknown_message_is_a_404(): void
    {
        $response = $this->request(
            'POST',
            '/api/admin/notifications/' . $this->generateUuid() . '/retry',
            [],
            headers: ['X-CSRF-Token' => $this->csrfToken]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_retrying_requires_a_session(): void
    {
        $id = $this->queue();
        $this->markFailed($id);
        $_SESSION = [];

        $this->assertSame(401, $this->request('POST', "/api/admin/notifications/{$id}/retry")->getStatusCode());
    }
}
