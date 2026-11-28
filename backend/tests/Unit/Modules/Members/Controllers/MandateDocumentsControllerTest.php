<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Members\Controllers\MandateDocumentsController;
use App\Modules\Members\DTOs\OpenMandatePrintDto;
use App\Modules\Members\Services\OpenMandatePrintService;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Security\Services\PrivateKeyMismatchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The boundary of the mandate print (UC-A18): which of the two prints a
 * request asks for, and what the keyed one has to prove first.
 *
 * The keyed half is checked against the same four behaviours the SEPA export
 * has — step-up before anything, 401 for a refused credential, 422 for a key
 * that is not the club's, `no-store` on the answer — because it is the same
 * secret travelling the same way.
 */
final class MandateDocumentsControllerTest extends TestCase
{
    private const ADMIN = '44444444-4444-4444-4444-444444444444';
    private const MEMBER = '33333333-3333-4333-8333-333333333333';
    private const KEY = 'c2VjcmV0LWtleS1mb3ItdGVzdHMtMzItYnl0ZXMtbG9uZyE=';

    private OpenMandatePrintService&MockObject $mandates;
    private EncryptionKeyService&MockObject $keys;
    private StepUpAuthService&MockObject $stepUp;
    private MandateDocumentsController $controller;

    protected function setUp(): void
    {
        $this->mandates = $this->createMock(OpenMandatePrintService::class);
        $this->keys = $this->createMock(EncryptionKeyService::class);
        $this->stepUp = $this->createMock(StepUpAuthService::class);

        $this->controller = new MandateDocumentsController($this->mandates, $this->keys, $this->stepUp);
    }

    /** @param array<string, mixed> $body */
    private function send(array $body): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/members/mandate-documents')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', self::ADMIN)
            ->withAttribute('admin_user', ['id' => self::ADMIN, 'totp_enabled' => 0]);

        return $this->controller->print($request, new Response());
    }

    private function printed(int $sheets = 2, int $withoutIban = 0): OpenMandatePrintDto
    {
        return new OpenMandatePrintDto('%PDF-1.4 test', $sheets, $withoutIban);
    }

    // ── the blank print ──────────────────────────────────────────────────

    /** No key in the request means no secret is needed, so no credential is asked for. */
    public function test_the_blank_print_asks_for_no_step_up(): void
    {
        $this->stepUp->expects(self::never())->method('verify');
        $this->keys->expects(self::never())->method('withActivePrivateKey');
        $this->mandates->expects(self::once())->method('render')
            ->with(null, null, self::ADMIN)
            ->willReturn($this->printed());

        $response = $this->send([]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('%PDF-1.4 test', (string) $response->getBody());
    }

    /**
     * The sheets carry names, birth dates and addresses — and on the keyed
     * print, IBANs in the clear. A cached copy on a shared clubhouse machine
     * is the leak everything else here exists to prevent.
     */
    public function test_the_document_is_never_cached(): void
    {
        $this->mandates->method('render')->willReturn($this->printed());

        $response = $this->send([]);

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('offene-mandate-', $response->getHeaderLine('Content-Disposition'));
    }

    /** The body is a PDF and cannot carry a remark, so the counts ride beside it. */
    public function test_the_counts_travel_on_headers(): void
    {
        $this->mandates->method('render')->willReturn($this->printed(sheets: 40, withoutIban: 3));

        $response = $this->send([]);

        self::assertSame('40', $response->getHeaderLine('X-Mandate-Sheets'));
        self::assertSame('3', $response->getHeaderLine('X-Mandate-Sheets-Without-Iban'));
    }

    public function test_the_print_can_be_narrowed_to_named_members(): void
    {
        $this->mandates->expects(self::once())->method('render')
            ->with([self::MEMBER], null, self::ADMIN)
            ->willReturn($this->printed(1));

        self::assertSame(200, $this->send(['member_ids' => [self::MEMBER, self::MEMBER]])->getStatusCode());
    }

    /** @return array<string, array{mixed}> */
    public static function unusableMemberLists(): array
    {
        return [
            'an empty list' => [[]],
            'a single id, not a list' => [self::MEMBER],
            'a map' => [['a' => self::MEMBER]],
            'something that is not an id' => [[self::MEMBER, 'everyone']],
            'more than the cap' => [array_fill(0, MandateDocumentsController::MEMBER_LIMIT + 1, self::MEMBER)],
        ];
    }

    #[DataProvider('unusableMemberLists')]
    public function test_an_unusable_member_list_never_reaches_the_service(mixed $memberIds): void
    {
        $this->mandates->expects(self::never())->method('render');

        $response = $this->send(['member_ids' => $memberIds]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('member_ids', json_decode((string) $response->getBody(), true)['messages']);
    }

    // ── the keyed print ──────────────────────────────────────────────────

    /**
     * Somebody chose the full-IBAN variant and the key did not arrive.
     * Answering with a blank document would look like the request had worked.
     *
     * @return array<string, array{mixed}>
     */
    public static function emptyKeys(): array
    {
        return ['an empty string' => [''], 'only whitespace' => ['   '], 'not a string' => [['x']]];
    }

    #[DataProvider('emptyKeys')]
    public function test_an_empty_key_is_refused_rather_than_read_as_the_blank_print(mixed $key): void
    {
        $this->mandates->expects(self::never())->method('render');
        $this->stepUp->expects(self::never())->method('verify');

        $response = $this->send(['private_key' => $key]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('private_key', json_decode((string) $response->getBody(), true)['messages']);
    }

    /** The credential is checked before the key is so much as looked at. */
    public function test_a_refused_step_up_is_a_401_and_nothing_is_decrypted(): void
    {
        $this->stepUp->method('verify')->willReturn(false);
        $this->keys->expects(self::never())->method('withActivePrivateKey');
        $this->mandates->expects(self::never())->method('render');

        $response = $this->send(['private_key' => self::KEY, 'current_password' => 'wrong']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_credentials', json_decode((string) $response->getBody(), true)['error']);
    }

    public function test_a_key_that_is_not_the_club_s_is_a_422(): void
    {
        $this->stepUp->method('verify')->willReturn(true);
        $this->keys->method('withActivePrivateKey')
            ->willThrowException(new PrivateKeyMismatchException('The supplied private key does not match key "club-2026".'));

        $response = $this->send(['private_key' => self::KEY, 'current_password' => 'right']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('private_key_mismatch', json_decode((string) $response->getBody(), true)['error']);
    }

    /**
     * The opener exists only inside `withActivePrivateKey()`; the controller
     * hands the service that opener and never the key.
     */
    public function test_the_keyed_print_renders_inside_the_key_s_lifetime(): void
    {
        $opener = static fn(string $ciphertext): string => 'plain';

        $this->stepUp->method('verify')->willReturn(true);
        $this->keys->expects(self::once())->method('withActivePrivateKey')
            ->with(self::KEY, self::isCallable())
            ->willReturnCallback(static fn(string $key, callable $work) => $work($opener));
        $this->mandates->expects(self::once())->method('render')
            ->with([self::MEMBER], $opener, self::ADMIN)
            ->willReturn($this->printed(1));

        $response = $this->send([
            'private_key' => self::KEY,
            'current_password' => 'right',
            'member_ids' => [self::MEMBER],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }
}
