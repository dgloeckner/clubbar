<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Services;

use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Registrations\DTOs\SelfRegistrationSettingsDto;
use App\Modules\Registrations\Services\RegistrationLinkService;
use App\Modules\Registrations\Services\SelfRegistrationAdminService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sending the Anmeldelink (#821, ADR-0053, UC-A70).
 *
 * Three claims, and they are the three the design would be wrong without:
 *
 * 1. **A club that cannot answer the link does not send one**, refused by the
 *    switch's own typed reason so the admin is told which precondition to fix.
 * 2. **Sending twice sends twice.** The outbox's unique index exists so a
 *    repeating scan is idempotent; there is no scan here, and a key of the bare
 *    address would silently swallow the re-send that answers "I never got it".
 * 3. **The row names nobody.** Both id columns are null, because this person
 *    has no record in this database and — if they never join — never will.
 */
class RegistrationLinkServiceTest extends TestCase
{
    private const ADMIN = '44444444-4444-4444-4444-444444444444';

    /** @var list<MailRequestDto> */
    private array $queued = [];
    /** @var list<array{action: AuditAction, entityType: EntityType, entityId: string, newValues: ?array, adminUserId: ?string}> */
    private array $audited = [];

    protected function setUp(): void
    {
        $this->queued = [];
        $this->audited = [];
    }

    private function service(
        bool $enabled = true,
        bool $hasSecret = true,
        ?string $documentUrl = 'https://club.example/Anmeldung.pdf',
    ): RegistrationLinkService {
        $registrations = $this->createMock(SelfRegistrationAdminService::class);
        $registrations->method('settings')->willReturn(new SelfRegistrationSettingsDto(
            enabled: $enabled,
            disabledReason: $enabled ? null : 'Beta-Phase schon voll',
            hasSecret: $hasSecret,
            secretRotatedAt: '2026-03-01 09:00:00',
            documentUrl: $documentUrl,
            retentionDays: 30,
        ));

        $outbox = $this->createMock(MailOutboxRepository::class);
        $outbox->method('enqueue')->willReturnCallback(function (MailRequestDto $request): bool {
            $this->queued[] = $request;

            return true;
        });

        $audit = $this->createMock(AuditService::class);
        $audit->method('log')->willReturnCallback(
            function (
                AuditAction $action,
                EntityType $entityType,
                string $entityId,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?string $adminUserId = null,
            ): void {
                $this->audited[] = compact('action', 'entityType', 'entityId', 'newValues', 'adminUserId');
            }
        );

        return new RegistrationLinkService(
            $registrations,
            $outbox,
            $audit,
            $this->createMock(Logger::class),
        );
    }

    // ── the gate (decision 8) ────────────────────────────────────────────

    /**
     * A poster has an excuse for going stale — it is paper, printed months ago.
     * A message composed one second ago has none, and mailing a link to a
     * refusal page makes the club look broken to the person it is courting.
     *
     * @param array<string,mixed> $state
     */
    #[DataProvider('unavailableStates')]
    public function test_a_club_that_could_not_answer_the_link_refuses_to_send_it(
        array $state,
        BusinessRuleReason $expected,
    ): void {
        $service = $this->service(...$state);

        try {
            $service->send('interessent@example.org', self::ADMIN);
            $this->fail('the send should have been refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame($expected, $e->getReason());
        }

        // Nothing queued and nothing audited: a refusal is not a half-send.
        $this->assertSame([], $this->queued);
        $this->assertSame([], $this->audited);
    }

    /** @return array<string, array{0: array<string,mixed>, 1: BusinessRuleReason}> */
    public static function unavailableStates(): array
    {
        return [
            'switched off' => [['enabled' => false], BusinessRuleReason::REGISTRATION_DISABLED],
            'no poster secret' => [['hasSecret' => false], BusinessRuleReason::REGISTRATION_NO_SECRET],
            'no club document' => [['documentUrl' => null], BusinessRuleReason::DOCUMENT_URL_MISSING],
            'document url blanked' => [['documentUrl' => '  '], BusinessRuleReason::DOCUMENT_URL_MISSING],
        ];
    }

    // ── the queued row ───────────────────────────────────────────────────

    public function test_it_queues_one_row_naming_nobody(): void
    {
        $this->service()->send('interessent@example.org', self::ADMIN);

        $this->assertCount(1, $this->queued);
        $request = $this->queued[0];

        $this->assertSame(MailKind::REGISTRATION_LINK, $request->kind);
        $this->assertSame('interessent@example.org', $request->recipient);
        $this->assertSame(MailRequestDto::SELF_REGISTRATION_SUBJECT_ID, $request->subjectId);
        // The whole design in two assertions: nothing is stored about somebody
        // who may never join, so there is no id to carry and nothing for
        // erasure to find (ADR-0052 decision 10).
        $this->assertNull($request->memberId);
        $this->assertNull($request->adminUserId);
        $this->assertTrue($request->addressedToProspect);
        $this->assertFalse($request->addressedToClub);
    }

    /**
     * German, frozen at enqueue. There is no club-level default to read
     * (`instance_config` holds the club's name and nothing else) and inventing
     * one as a side effect of this feature was rejected — it belongs to #820.
     */
    public function test_the_language_is_frozen_at_enqueue(): void
    {
        $this->service()->send('interessent@example.org', self::ADMIN);

        $this->assertSame(MailLanguage::German, $this->queued[0]->language);
    }

    /**
     * The regression test for decision 4. A `dedup_key` of the bare address
     * would let `UNIQUE (kind, subject_id, dedup_key)` refuse the second send
     * from inside the database — silently, behind a 202 — and the second send
     * is precisely the one that answers "I never got it".
     */
    public function test_sending_twice_to_one_address_queues_twice(): void
    {
        $service = $this->service();

        $service->send('interessent@example.org', self::ADMIN);
        $service->send('interessent@example.org', self::ADMIN);

        $this->assertCount(2, $this->queued);
        $this->assertNotSame(
            $this->queued[0]->dedupKey,
            $this->queued[1]->dedupKey,
            'a per-send nonce is what makes the re-send a second message rather than one the index swallows',
        );
    }

    public function test_the_address_is_trimmed_before_it_is_stored(): void
    {
        $this->service()->send('  interessent@example.org  ', self::ADMIN);

        $this->assertSame('interessent@example.org', $this->queued[0]->recipient);
        $this->assertSame('interessent@example.org', $this->audited[0]['newValues']['recipient']);
    }

    // ── the audit entry (decision 9) ─────────────────────────────────────

    /**
     * An admin causing the installation to mail a named third party is the
     * shape of everything else in this log, and the address is part of the
     * entry rather than exempted from it — it ages out with the log.
     */
    public function test_the_audit_entry_names_the_admin_the_act_and_the_address(): void
    {
        $this->service()->send('interessent@example.org', self::ADMIN);

        $this->assertCount(1, $this->audited);
        $entry = $this->audited[0];

        $this->assertSame(AuditAction::REGISTRATION_LINK_SENT, $entry['action']);
        $this->assertSame(EntityType::SELF_REGISTRATION, $entry['entityType']);
        $this->assertSame(MailRequestDto::SELF_REGISTRATION_SUBJECT_ID, $entry['entityId']);
        $this->assertSame('interessent@example.org', $entry['newValues']['recipient']);
        $this->assertSame(self::ADMIN, $entry['adminUserId']);
    }
}
