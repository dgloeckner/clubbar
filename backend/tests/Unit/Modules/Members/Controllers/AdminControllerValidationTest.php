<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Controllers;

use App\Modules\Members\Controllers\AdminController;
use App\Modules\Members\DTOs\MemberAdminDto;
use App\Modules\Members\Services\MandateDocumentService;
use App\Modules\Members\Services\MembersService;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SepaConfigService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Member field validation on create and update (issue #117).
 *
 * `phone`, `email`, `mandate_reference` and `mandate_signed_at` carried no
 * length or format rule, so an over-long value or a malformed date reached
 * MariaDB in strict mode and returned a PDOException — a 500 that named
 * nothing. Update was worse: it checked three fields in three separate passes
 * and left the other seven unchecked entirely.
 */
class AdminControllerValidationTest extends TestCase
{
    private MembersService $membersService;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->membersService = $this->createMock(MembersService::class);

        $this->controller = new AdminController(
            $this->membersService,
            new Validator($this->createMock(\PDO::class)),
            $this->createMock(SepaConfigService::class),
            $this->createMock(MandateDocumentService::class),
            $this->createMock(SettlementsService::class),
            $this->createMock(CollectionHoldService::class),
        );
    }

    /** @return array<string, mixed> */
    private static function validBody(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
            'preferred_language' => 'de',
        ];
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/members')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1');
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/admin/members/m-1')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1');
    }

    /** @return array<string, mixed> */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function overlongOrMalformedFields(): array
    {
        return [
            'phone past VARCHAR(20)' => ['phone', str_repeat('9', 21)],
            'email past VARCHAR(255)' => ['email', str_repeat('a', 250) . '@example.org'],
            'mandate_reference past VARCHAR(35)' => ['mandate_reference', str_repeat('R', 36)],
            'mandate_signed_at as prose' => ['mandate_signed_at', 'next tuesday'],
            'mandate_signed_at as a non-existent day' => ['mandate_signed_at', '2026-02-30'],
            'first_name past VARCHAR(100)' => ['first_name', str_repeat('A', 101)],
            'last_name past VARCHAR(100)' => ['last_name', str_repeat('B', 101)],
            'account_holder_name past VARCHAR(70)' => ['account_holder_name', str_repeat('C', 71)],
            'iban past VARCHAR(34)' => ['iban', str_repeat('D', 35)],
        ];
    }

    #[DataProvider('overlongOrMalformedFields')]
    public function test_store_answers_422_naming_the_field(string $field, mixed $value): void
    {
        $this->membersService->expects($this->never())->method('createMember');

        $response = $this->controller->store($this->post(self::validBody([$field => $value])), new Response());

        $this->assertSame(422, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey($field, $body['messages'], "the 422 must name {$field}");
    }

    #[DataProvider('overlongOrMalformedFields')]
    public function test_update_answers_422_naming_the_field(string $field, mixed $value): void
    {
        $this->membersService->expects($this->never())->method('updateMember');

        $response = $this->controller->update($this->patch([$field => $value]), new Response(), ['memberId' => 'm-1']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey($field, $this->decode($response)['messages'], "the 422 must name {$field}");
    }

    public function test_store_accepts_a_body_that_fits_every_column(): void
    {
        $this->membersService->expects($this->once())
            ->method('createMember')
            ->willReturn($this->member());

        $response = $this->controller->store($this->post(self::validBody([
            'phone' => '+49 170 1234567',
            'mandate_reference' => 'MANDATE-0001',
            'mandate_signed_at' => '2026-01-15',
        ])), new Response());

        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * A PATCH naming one field must not be rejected for the absence of the
     * others — every rule in the set is `nullable` on this path.
     */
    public function test_update_validates_only_the_fields_the_request_carries(): void
    {
        $this->membersService->expects($this->once())
            ->method('updateMember')
            ->with('m-1', ['phone' => '+49 170 1234567'], 'admin-1')
            ->willReturn($this->member());

        $response = $this->controller->update(
            $this->patch(['phone' => '+49 170 1234567']),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_update_reports_every_offending_field_at_once(): void
    {
        $response = $this->controller->update(
            $this->patch(['phone' => str_repeat('9', 21), 'mandate_signed_at' => 'whenever']),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(422, $response->getStatusCode());

        $messages = $this->decode($response)['messages'];
        $this->assertArrayHasKey('phone', $messages);
        $this->assertArrayHasKey('mandate_signed_at', $messages);
    }

    /** @return array<string, array{0: string}> */
    public static function clearableFields(): array
    {
        return [
            'phone' => ['phone'],
            'card_uid' => ['card_uid'],
            'iban' => ['iban'],
            'account_holder_name' => ['account_holder_name'],
            'mandate_signed_at' => ['mandate_signed_at'],
        ];
    }

    /**
     * A field the volunteer cleared in the form arrives as `""`, and the update
     * path used to hand that on verbatim (#111) — so the member kept an empty
     * string where the create path would have stored no value at all, and
     * `card_uid` could not be cleared at all because a blank fails its own
     * `min:8` rule.
     */
    #[DataProvider('clearableFields')]
    public function test_update_clears_a_blank_field_to_null(string $field): void
    {
        // Strictly: `''` and `null` are loosely equal in PHP, so the default
        // constraint would accept the very value this test exists to reject.
        $this->membersService->expects($this->once())
            ->method('updateMember')
            ->with('m-1', $this->identicalTo([$field => null]), 'admin-1')
            ->willReturn($this->member());

        $response = $this->controller->update($this->patch([$field => '']), new Response(), ['memberId' => 'm-1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    /** The same body must mean the same thing on both paths. */
    #[DataProvider('clearableFields')]
    public function test_store_reads_a_blank_field_as_no_value(string $field): void
    {
        $this->membersService->expects($this->once())
            ->method('createMember')
            ->with(
                'Ada',
                'Lovelace',
                'ada@example.org',
                $this->identicalTo(null),
                $this->identicalTo(null),
                $this->anything(),
                $this->identicalTo(null),
                $this->identicalTo(null),
                $this->identicalTo(null),
                $this->identicalTo(null),
                'admin-1',
            )
            ->willReturn($this->member());

        $response = $this->controller->store($this->post(self::validBody([$field => ''])), new Response());

        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * `card_uid` is the one clearable field carrying a length rule, so a blank
     * that reached the validator came back as "must be at least 8 characters" —
     * a 422 on a member whose card was simply handed back.
     */
    public function test_a_blank_card_uid_is_not_reported_as_too_short(): void
    {
        $this->membersService->expects($this->once())
            ->method('updateMember')
            ->willReturn($this->member());

        $response = $this->controller->update($this->patch(['card_uid' => '']), new Response(), ['memberId' => 'm-1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Blank does not mean absent for the mandate reference: an absent one is
     * minted from the member id (ADR-0006), a blank one says this member has no
     * mandate and must leave them without one (#164). Normalizing it away would
     * mint a reference over the admin's explicit "there is none".
     */
    public function test_store_passes_a_blank_mandate_reference_through_unchanged(): void
    {
        $this->membersService->expects($this->once())
            ->method('createMember')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->identicalTo(''),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->member());

        $response = $this->controller->store(
            $this->post(self::validBody(['mandate_reference' => ''])),
            new Response(),
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_update_still_accepts_an_empty_body(): void
    {
        $this->membersService->expects($this->once())
            ->method('updateMember')
            ->willReturn($this->member());

        $response = $this->controller->update($this->patch([]), new Response(), ['memberId' => 'm-1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function member(): MemberAdminDto
    {
        return MemberAdminDto::fromRow([
            'id' => 'm-1',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
            'preferred_language' => 'de',
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}
