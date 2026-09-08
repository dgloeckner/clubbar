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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Only the canonical spelling of a card UID reaches the column.
 *
 * The admin panel is where a card is assigned (ADR-0021), and the value is
 * typed or pasted from whatever the volunteer had to hand: the label on the
 * card, a reader's diagnostic window, a note from the last committee meeting.
 * Those print the same chip as `001EB4CB`, `001eb4cb`, `00:1E:B4:CB`,
 * `0x001EB4CB` or `1EB4CB`, and `members.card_uid` is matched by exact string
 * comparison.
 *
 * Storing whichever arrived has two failures, and the second is the quiet one:
 * the card works only until somebody swaps the reader, and the uniqueness check
 * does not notice that `001EB4CB` and `00:1E:B4:CB` are one card being handed
 * to two members.
 */
class AdminControllerCardUidTest extends TestCase
{
    private MembersService $membersService;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->membersService = $this->createMock(MembersService::class);

        $this->controller = new AdminController(
            $this->membersService,
            new Validator($this->uniquenessAlwaysSatisfied()),
            $this->createMock(SettlementsService::class),
            $this->createMock(CollectionHoldService::class),
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function dialects(): array
    {
        return [
            'already canonical'         => ['001EB4CB', '001EB4CB'],
            'lower case'                => ['001eb4cb', '001EB4CB'],
            'mixed case'                => ['001Eb4Cb', '001EB4CB'],
            'colon separated'           => ['00:1E:B4:CB', '001EB4CB'],
            'hyphen separated'          => ['00-1e-b4-cb', '001EB4CB'],
            'space separated'           => ['00 1E B4 CB', '001EB4CB'],
            '0x prefixed'               => ['0x001EB4CB', '001EB4CB'],
            'pasted with whitespace'    => ["  001eb4cb\n", '001EB4CB'],
            'half-written byte'         => ['1EB4CBA', '01EB4CBA'],
        ];
    }

    #[DataProvider('dialects')]
    public function test_store_files_every_dialect_under_one_card_uid(
        string $typed,
        string $stored,
    ): void {
        $this->membersService->expects($this->once())
            ->method('createMember')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $stored,
            )
            ->willReturn($this->member());

        $response = $this->controller->store($this->post(self::validBody(['card_uid' => $typed])), new Response());

        $this->assertSame(201, $response->getStatusCode(), $this->explain($response));
    }

    #[DataProvider('dialects')]
    public function test_update_files_every_dialect_under_one_card_uid(
        string $typed,
        string $stored,
    ): void {
        $this->membersService->expects($this->once())
            ->method('updateMember')
            ->with('m-1', ['card_uid' => $stored], 'admin-1')
            ->willReturn($this->member());

        $response = $this->controller->update(
            $this->patch(['card_uid' => $typed]),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(200, $response->getStatusCode(), $this->explain($response));
    }

    /**
     * A value that is not a card UID in any dialect is left exactly as it
     * arrived, so the format rule produces the message rather than the
     * normalizer silently mangling it into something that looks like a card.
     *
     * @return array<string, array{string}>
     */
    public static function refusedValues(): array
    {
        return [
            'not hex'         => ['GHIJKLMN'],
            'past the column' => ['AABBCCDDEEFF001122334'],
            // Three whole bytes. Not padded up to four: on this side the input
            // comes from fingers, and refusing a half-typed value is the typo
            // defence the member form has always had.
            'three bytes'     => ['1EB4CB'],
            'a slip of the hand' => ['ABCD'],
            'prose'              => ['die blaue Karte'],
        ];
    }

    #[DataProvider('refusedValues')]
    public function test_store_answers_422_for_what_is_not_a_card_uid(string $typed): void
    {
        $this->membersService->expects($this->never())->method('createMember');

        $response = $this->controller->store($this->post(self::validBody(['card_uid' => $typed])), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('card_uid', $this->decode($response)['messages']);
    }

    /**
     * The `ANON-…` placeholder an anonymized member carries is not hex and is
     * refused here like any other non-UID. It never travels this path —
     * `anonymize()` writes it straight to the row — and this is the assertion
     * that the normalizer cannot rewrite it into something card-shaped.
     */
    public function test_the_anonymization_placeholder_is_not_treated_as_a_card(): void
    {
        $this->membersService->expects($this->never())->method('updateMember');

        $response = $this->controller->update(
            $this->patch(['card_uid' => 'ANON-8ba7b8109dad11d']),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    /** Clearing the field still means "the card was handed back" (#111). */
    public function test_a_cleared_card_field_still_clears_the_card(): void
    {
        $this->membersService->expects($this->once())
            ->method('updateMember')
            ->with('m-1', ['card_uid' => null], 'admin-1')
            ->willReturn($this->member());

        $response = $this->controller->update(
            $this->patch(['card_uid' => '']),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A decimal reader's output is stored as the hex it literally reads as,
     * not as the value it stands for.
     *
     * `0002012363` is the decimal spelling of `001EB4CB` *and* a well-formed
     * 5-byte hex UID, and nothing in the string says which. The backend is the
     * store of record, so it does not guess: a wrong guess files a card under a
     * UID no reader will ever produce, and nothing about the failure says so.
     * Decimal is resolved where the answer is known — the terminal's configured
     * reader profile, or an admin converting the value explicitly in the form.
     */
    public function test_a_digits_only_uid_is_stored_as_hex_not_read_as_decimal(): void
    {
        $this->membersService->expects($this->once())
            ->method('updateMember')
            ->with('m-1', ['card_uid' => '0002012363'], 'admin-1')
            ->willReturn($this->member());

        $response = $this->controller->update(
            $this->patch(['card_uid' => '0002012363']),
            new Response(),
            ['memberId' => 'm-1'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── plumbing ────────────────────────────────────────────────────────────

    /**
     * A PDO whose `unique:` lookup always answers "nobody has this card".
     *
     * The uniqueness rule runs against the *canonical* value, which is the
     * point: two members cannot be given `001EB4CB` and `00:1E:B4:CB`.
     */
    private function uniquenessAlwaysSatisfied(): \PDO
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn(0);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
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
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    /** The 422 body, so a failing assertion names the rule that refused. */
    private function explain(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
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
