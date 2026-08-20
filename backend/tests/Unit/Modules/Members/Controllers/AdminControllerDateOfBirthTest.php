<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Controllers;

use App\Modules\Members\Controllers\AdminController;
use App\Modules\Members\DTOs\MemberAdminDto;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\Members\Services\MembersService;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * `date_of_birth` on the member write paths (epic #582, M2, ADR-0045).
 *
 * The field is **mandatory on create and nullable in the column**, which is the
 * `first_name`/`last_name` shape already in use here. The split is load-bearing
 * rather than incidental: `required` in the rules is what removes the
 * "unknown age, allow anyway" branch from the terminal's Jugendschutz check,
 * and `NULL` in the column is what lets GDPR erasure clear it (ADR-0029, OLG
 * Dresden 4 U 1278/21). A NULL birth date therefore means exactly one thing —
 * an anonymized member.
 *
 * Update deliberately does *not* require it. A PATCH naming one field must not
 * be rejected for the absence of the others, and the field cannot be cleared by
 * an admin at all: it is not in `BLANK_MEANS_NULL`, so a blank one is a
 * validation error rather than an erasure the edit form can perform by accident.
 */
class AdminControllerDateOfBirthTest extends TestCase
{
    private MembersService $membersService;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->membersService = $this->createMock(MembersService::class);

        $this->controller = new AdminController(
            $this->membersService,
            new Validator($this->createMock(\PDO::class)),
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
            'date_of_birth' => '1990-05-04',
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

    private function member(): MemberAdminDto
    {
        return MemberAdminDto::fromRow([
            'id' => 'm-1',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
            'preferred_language' => 'de',
            'date_of_birth' => '1990-05-04',
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private static function daysFromToday(int $days): string
    {
        return (new \DateTimeImmutable('today'))
            ->modify(sprintf('%+d days', $days))
            ->format('Y-m-d');
    }

    // -- create ------------------------------------------------------------

    public function test_store_threads_the_date_of_birth_to_the_service(): void
    {
        $this->membersService->expects($this->once())
            ->method('createMember')
            ->with(
                'Ada',
                'Lovelace',
                'ada@example.org',
                null,
                null,
                SupportedLanguage::from('de'),
                null,
                null,
                null,
                null,
                '1990-05-04',
                'admin-1',
            )
            ->willReturn($this->member());

        $response = $this->controller->store($this->post(self::validBody()), new Response());

        $this->assertSame(201, $response->getStatusCode());
    }

    /** @return array<string, array{0: mixed}> */
    public static function absentDatesOfBirth(): array
    {
        return [
            'omitted entirely' => [null],
            'sent blank by a form' => [''],
        ];
    }

    /**
     * Mandatory means mandatory. If the field could be skipped, every member
     * created that way would need a fail-open branch at the terminal — which is
     * exactly the branch ADR-0045 decision 2 exists to remove.
     */
    #[DataProvider('absentDatesOfBirth')]
    public function test_store_rejects_a_member_without_one(mixed $value): void
    {
        $this->membersService->expects($this->never())->method('createMember');

        $body = self::validBody();
        if ($value === null) {
            unset($body['date_of_birth']);
        } else {
            $body['date_of_birth'] = $value;
        }

        $response = $this->controller->store($this->post($body), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('date_of_birth', $this->decode($response)['messages']);
    }

    /** @return array<string, array{0: string}> */
    public static function unusableDates(): array
    {
        return [
            'prose' => ['sometime in the eighties'],
            'relative' => ['-30 years'],
            'a non-existent day' => ['1990-02-30'],
        ];
    }

    #[DataProvider('unusableDates')]
    public function test_store_rejects_a_malformed_date(string $value): void
    {
        $this->membersService->expects($this->never())->method('createMember');

        $response = $this->controller->store(
            $this->post(self::validBody(['date_of_birth' => $value])),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('date_of_birth', $this->decode($response)['messages']);
    }

    /**
     * A member born tomorrow is younger than every `min_age` forever, so the
     * Jugendschutz check would refuse them permanently — a typo that reads as a
     * legal refusal.
     */
    public function test_store_rejects_a_birth_date_in_the_future(): void
    {
        $this->membersService->expects($this->never())->method('createMember');

        $response = $this->controller->store(
            $this->post(self::validBody(['date_of_birth' => self::daysFromToday(1)])),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertContains(
            'date_of_birth must not be in the future',
            $this->decode($response)['messages']['date_of_birth'],
        );
    }

    public function test_store_accepts_a_member_born_today(): void
    {
        $this->membersService->expects($this->once())->method('createMember')->willReturn($this->member());

        $response = $this->controller->store(
            $this->post(self::validBody(['date_of_birth' => self::daysFromToday(0)])),
            new Response(),
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    // -- update ------------------------------------------------------------

    public function test_update_passes_a_corrected_date_through(): void
    {
        $this->membersService->expects($this->once())
            ->method('updateMember')
            ->with('m-1', ['date_of_birth' => '1815-12-10'], 'admin-1')
            ->willReturn($this->member());

        $response = $this->controller->update(
            $this->patch(['date_of_birth' => '1815-12-10']),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /** A PATCH of one other field is not a statement about the birth date. */
    public function test_update_does_not_require_it(): void
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

    public function test_update_rejects_a_birth_date_in_the_future(): void
    {
        $this->membersService->expects($this->never())->method('updateMember');

        $response = $this->controller->update(
            $this->patch(['date_of_birth' => self::daysFromToday(365)]),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('date_of_birth', $this->decode($response)['messages']);
    }

    /**
     * The edit form cannot erase a birth date.
     *
     * Erasure is `anonymizeMember()`'s job and nothing else's — it is
     * irreversible and reserved to the `admin` role (ADR-0044). If a blank
     * normalized to null here like `phone` does, an admin clearing the field by
     * accident would produce a member the terminal reads as anonymized
     * (ADR-0045 rule 3) while they are still active and still buying drinks.
     */
    public function test_update_refuses_to_clear_it(): void
    {
        $this->membersService->expects($this->never())->method('updateMember');

        $response = $this->controller->update(
            $this->patch(['date_of_birth' => '']),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('date_of_birth', $this->decode($response)['messages']);
    }

    // -- the field the same rule fixes ------------------------------------

    /**
     * `mandate_signed_at` gains `past_date` in the same change. It carried only
     * `['nullable', 'date']`, so a mandate could be recorded as signed next
     * month — the same class of mistake, on a field whose date decides whether
     * a collection is covered.
     */
    public function test_store_rejects_a_mandate_signed_in_the_future(): void
    {
        $this->membersService->expects($this->never())->method('createMember');

        $response = $this->controller->store(
            $this->post(self::validBody(['mandate_signed_at' => self::daysFromToday(30)])),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('mandate_signed_at', $this->decode($response)['messages']);
    }

    public function test_update_rejects_a_mandate_signed_in_the_future(): void
    {
        $this->membersService->expects($this->never())->method('updateMember');

        $response = $this->controller->update(
            $this->patch(['mandate_signed_at' => self::daysFromToday(1)]),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('mandate_signed_at', $this->decode($response)['messages']);
    }

    // -- the response ------------------------------------------------------

    public function test_the_created_member_carries_the_date_of_birth_back(): void
    {
        $this->membersService->method('createMember')->willReturn($this->member());

        $response = $this->controller->store($this->post(self::validBody()), new Response());

        $this->assertSame('1990-05-04', $this->decode($response)['date_of_birth']);
    }
}
