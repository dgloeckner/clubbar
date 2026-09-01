<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Controllers;

use App\Modules\Members\DTOs\MemberAdminDto;
use App\Modules\Registrations\Controllers\AdminController;
use App\Modules\Registrations\DTOs\PendingRegistrationDto;
use App\Modules\Registrations\Services\RegistrationReviewService;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Validation\Validator;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The boundary of the review surface: what a request may carry, and what the
 * controller refuses before the service is asked.
 *
 * The service has its own tests for the attestation, the duplicate refusal and
 * the audit entries; this one is about validation and about which arguments
 * actually reach it.
 */
final class AdminControllerTest extends TestCase
{
    private const ID = '33333333-3333-4333-8333-333333333333';
    private const ADMIN = '44444444-4444-4444-4444-444444444444';

    private AdminController $controller;
    private RecordingReviewService $service;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->service = new RecordingReviewService();
        $this->controller = new AdminController($this->service, new Validator($db));
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, array $body, string $path = ''): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/admin/registrations/' . self::ID . $path)
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', self::ADMIN);

        return match ($path) {
            '/approve' => $this->controller->approve($request, new Response(), ['registrationId' => self::ID]),
            '/reject' => $this->controller->reject($request, new Response(), ['registrationId' => self::ID]),
            default => $this->controller->update($request, new Response(), ['registrationId' => self::ID]),
        };
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    // ── list and detail ──────────────────────────────────────────────────

    public function test_the_list_is_paginated_in_the_shared_envelope(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/registrations')
            ->withQueryParams(['per_page' => '2', 'page' => '1']);

        $response = $this->controller->index($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('pagination', $body);
        self::assertSame(2, $this->service->listArgs['limit']);
    }

    public function test_the_detail_masks_the_iban_and_omits_the_sealed_material(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/registrations/' . self::ID);

        $body = $this->decode($this->controller->show($request, new Response(), ['registrationId' => self::ID]));

        self::assertSame('****3000', $body['iban_masked']);
        self::assertArrayNotHasKey('iban_ciphertext', $body);
        self::assertArrayNotHasKey('iban_fingerprint', $body);
    }

    // ── editing ──────────────────────────────────────────────────────────

    public function test_an_edit_reaches_the_service_with_the_acting_admin(): void
    {
        $response = $this->send('PATCH', ['first_name' => 'Magdalena']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['first_name' => 'Magdalena'], $this->service->updateArgs['data']);
        self::assertSame(self::ADMIN, $this->service->updateArgs['adminUserId']);
    }

    /**
     * A PATCH names only what it changes. Requiring the rest would make
     * correcting one typo a resubmission of the whole form.
     */
    public function test_a_partial_edit_is_not_refused_for_the_fields_it_omits(): void
    {
        self::assertSame(200, $this->send('PATCH', ['phone' => '+49 69 1234'])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidEdits')]
    public function test_an_invalid_edit_is_refused(array $body, string $field): void
    {
        $response = $this->send('PATCH', $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, $this->decode($response)['messages']);
        self::assertNull($this->service->updateArgs, 'The service must not be asked at all.');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidEdits(): array
    {
        return [
            'malformed email' => [['email' => 'not-an-address'], 'email'],
            'IBAN failing its checksum' => [['iban' => 'DE89370400440532013001'], 'iban'],
            'a birth date in the future' => [['date_of_birth' => '2999-01-01'], 'date_of_birth'],
            'a language the club does not run' => [['preferred_language' => 'zz'], 'preferred_language'],
            'an over-long name' => [['first_name' => str_repeat('a', 101)], 'first_name'],
        ];
    }

    /**
     * The reference is printed on the paper the member signed (ADR-0006), and
     * the notice URL is the club's evidence of what was shown before any data
     * was collected. Neither is an admin's to rewrite, so both are dropped
     * rather than refused — a form that sends the whole row back should not
     * fail for echoing a field it never edited.
     */
    public function test_an_edit_cannot_rewrite_the_reference_or_the_notice_it_records(): void
    {
        $this->send('PATCH', [
            'first_name' => 'Magdalena',
            'mandate_reference' => 'somebodyelses',
            'privacy_notice_url' => 'https://elsewhere.example/nothing.pdf',
            'expires_at' => '2099-01-01 00:00:00',
        ]);

        self::assertSame(['first_name' => 'Magdalena'], $this->service->updateArgs['data']);
    }

    // ── approving ────────────────────────────────────────────────────────

    public function test_an_attested_approval_creates_the_member(): void
    {
        $response = $this->send('POST', [
            'mandate_signed_at' => '2026-08-30',
            'signed_mandate_confirmed' => true,
        ], '/approve');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('2026-08-30', $this->service->approveArgs['signedAt']);
        self::assertTrue($this->service->approveArgs['confirmed']);
    }

    /**
     * A missing attestation is a *validation* error — the request did not say
     * anything. An explicit `false` is not: it is somebody stating they cannot
     * attest, and the service answers that with a translatable reason. The two
     * are different conversations and this is where they part.
     */
    public function test_an_approval_that_says_nothing_about_the_attestation_is_refused(): void
    {
        $response = $this->send('POST', ['mandate_signed_at' => '2026-08-30'], '/approve');

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('signed_mandate_confirmed', $this->decode($response)['messages']);
        self::assertNull($this->service->approveArgs);
    }

    public function test_an_explicit_refusal_to_attest_reaches_the_service(): void
    {
        $this->send('POST', [
            'mandate_signed_at' => '2026-08-30',
            'signed_mandate_confirmed' => false,
        ], '/approve');

        self::assertFalse($this->service->approveArgs['confirmed']);
    }

    /**
     * The signature date is the day the paper was signed, so it cannot be in
     * the future — and it *can* be today, which is the ordinary case: an admin
     * approving at the desk while the member is standing there.
     */
    public function test_a_signature_date_in_the_future_is_refused(): void
    {
        $response = $this->send('POST', [
            'mandate_signed_at' => '2999-01-01',
            'signed_mandate_confirmed' => true,
        ], '/approve');

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('mandate_signed_at', $this->decode($response)['messages']);
    }

    public function test_a_signature_dated_today_is_accepted(): void
    {
        $response = $this->send('POST', [
            'mandate_signed_at' => date('Y-m-d'),
            'signed_mandate_confirmed' => true,
        ], '/approve');

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_an_approval_without_a_signature_date_is_refused(): void
    {
        $response = $this->send('POST', ['signed_mandate_confirmed' => true], '/approve');

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('mandate_signed_at', $this->decode($response)['messages']);
        self::assertNull($this->service->approveArgs);
    }

    // ── rejecting ────────────────────────────────────────────────────────

    public function test_a_rejection_carries_its_reason_and_answers_204(): void
    {
        $response = $this->send('POST', ['reason' => 'No signed mandate arrived'], '/reject');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('No signed mandate arrived', $this->service->rejectArgs['reason']);
        self::assertSame(self::ADMIN, $this->service->rejectArgs['adminUserId']);
    }

    public function test_a_rejection_needs_no_reason(): void
    {
        self::assertSame(204, $this->send('POST', [], '/reject')->getStatusCode());
        self::assertNull($this->service->rejectArgs['reason']);
    }

    public function test_an_over_long_rejection_reason_is_refused(): void
    {
        $response = $this->send('POST', ['reason' => str_repeat('x', 501)], '/reject');

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->service->rejectArgs);
    }
}

/**
 * A stand-in that records what it was asked rather than doing it.
 *
 * The service's own behaviour has its own test; what matters here is that the
 * controller passes the right arguments and nothing else — which a mock would
 * assert less legibly across twenty cases.
 */
final class RecordingReviewService extends RegistrationReviewService
{
    /** @var array<string, mixed>|null */
    public ?array $listArgs = null;
    /** @var array<string, mixed>|null */
    public ?array $updateArgs = null;
    /** @var array<string, mixed>|null */
    public ?array $approveArgs = null;
    /** @var array<string, mixed>|null */
    public ?array $rejectArgs = null;

    public function __construct()
    {
        // Deliberately not calling the parent constructor: nothing below
        // reaches a collaborator, and requiring eight of them to assert on a
        // controller's argument passing would test the wiring, not the
        // boundary.
    }

    public function list(
        int $limit,
        int $offset,
        string $sortKey = 'submitted_at',
        string $sortOrder = 'desc',
        ?string $search = null,
    ): PaginatedResultDto {
        $this->listArgs = compact('limit', 'offset', 'sortKey', 'sortOrder', 'search');

        return new PaginatedResultDto(items: [self::row()->toArray()], total: 1, limit: $limit, offset: $offset);
    }

    public function get(string $id): PendingRegistrationDto
    {
        return self::row();
    }

    public function update(string $id, array $data, ?string $adminUserId = null): PendingRegistrationDto
    {
        $this->updateArgs = compact('id', 'data', 'adminUserId');

        return self::row();
    }

    public function approve(
        string $id,
        string $mandateSignedAt,
        bool $attestationConfirmed,
        ?string $adminUserId = null,
    ): MemberAdminDto {
        $this->approveArgs = [
            'id' => $id,
            'signedAt' => $mandateSignedAt,
            'confirmed' => $attestationConfirmed,
            'adminUserId' => $adminUserId,
        ];

        return MemberAdminDto::fromRow([
            'id' => '77777777-7777-7777-7777-777777777777',
            'card_uid' => null, 'first_name' => 'Lena', 'last_name' => 'Brandt',
            'email' => 'lena@example.org', 'date_of_birth' => '1998-04-02', 'phone' => null,
            'preferred_language' => 'de', 'credit_limit_cents' => null, 'is_active' => 1,
            'account_holder_name' => null, 'iban_last4' => '3000', 'has_iban' => 1,
            'bank_name' => 'Sparkasse', 'mandate_reference' => 'ref', 'mandate_signed_at' => '2026-08-30',
            'balance_cents' => 0, 'deleted_at' => null,
            'created_at' => '2026-09-01 09:00:00', 'updated_at' => '2026-09-01 09:00:00',
        ]);
    }

    public function reject(string $id, ?string $reason = null, ?string $adminUserId = null): void
    {
        $this->rejectArgs = compact('id', 'reason', 'adminUserId');
    }

    private static function row(): PendingRegistrationDto
    {
        return PendingRegistrationDto::fromRow([
            'id' => '33333333-3333-4333-8333-333333333333',
            'first_name' => 'Lena', 'last_name' => 'Brandt', 'email' => 'lena@example.org',
            'phone' => null, 'date_of_birth' => '1998-04-02', 'preferred_language' => 'de',
            'account_holder_name' => null, 'mandate_reference' => 'abc',
            'iban_last4' => '3000', 'bank_name' => 'Sparkasse',
            'privacy_notice_url' => 'https://club.example/Anmeldung.pdf',
            'privacy_notice_shown_at' => '2026-08-31 10:00:00',
            'submitted_at' => '2026-08-31 10:00:00', 'expires_at' => '2026-09-30 10:00:00',
        ]);
    }
}
