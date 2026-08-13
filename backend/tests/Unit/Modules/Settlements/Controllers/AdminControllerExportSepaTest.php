<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Security\Services\EncryptionKeyExpiredException;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Settlements\Controllers\AdminController;
use App\Modules\Settlements\DTOs\SepaExportResultDto;
use App\Modules\Settlements\Services\SepaExportService;
use App\Modules\Settlements\Services\SettlementReversalService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\Enums\AuditAction;
use App\Shared\Services\AuditService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * SEPA export as the one legitimate bulk decryption (#393, ADR-0036).
 *
 * The private key lives offline in the club's archive and reaches the server
 * only in this request body, behind a fresh step-up. Everything here is about
 * what the endpoint refuses and what it records — the XML itself is
 * SepaExportServiceTest's subject.
 */
class AdminControllerExportSepaTest extends TestCase
{
    private SettlementsService $settlementsService;
    private SepaExportService $sepaExportService;
    private EncryptionKeyService $encryptionKeyService;
    private StepUpAuthService $stepUpAuthService;
    private AuditService $auditService;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->settlementsService = $this->createMock(SettlementsService::class);
        $this->sepaExportService = $this->createMock(SepaExportService::class);
        $this->encryptionKeyService = $this->createMock(EncryptionKeyService::class);
        $this->stepUpAuthService = $this->createMock(StepUpAuthService::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->controller = new AdminController(
            $this->settlementsService,
            $this->sepaExportService,
            new Validator($this->createMock(\PDO::class)),
            $this->createMock(SettlementReversalService::class),
            $this->encryptionKeyService,
            $this->stepUpAuthService,
            $this->auditService,
        );
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements/s-1/export/sepa-xml')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1')
            ->withAttribute('admin_user', ['id' => 'admin-1']);
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    private function validBody(): array
    {
        return ['private_key' => base64_encode(str_repeat("\x01", 32)), 'current_password' => 'hunter2'];
    }

    private function exportResult(): SepaExportResultDto
    {
        return new SepaExportResultDto(
            xml: '<?xml version="1.0"?><Document/>',
            excludedMembers: [],
            collectedAmountCents: 4500,
            settlementAmountCents: 4500,
            collectedMemberCount: 3,
        );
    }

    /** Let the service under test run the caller's closure, as the real one does. */
    private function keyServiceOpensWith(string $plaintext): void
    {
        $this->encryptionKeyService->method('withActivePrivateKey')
            ->willReturnCallback(fn(string $key, callable $work) => $work(fn(string $ct) => $plaintext));
    }

    public function test_it_refuses_without_a_step_up_credential(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(false);
        $this->encryptionKeyService->expects($this->never())->method('withActivePrivateKey');
        $this->sepaExportService->expects($this->never())->method('export');

        $response = $this->controller->exportSepa($this->request($this->validBody()), new Response(), ['id' => 's-1']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
    }

    public function test_it_refuses_without_a_private_key(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->sepaExportService->expects($this->never())->method('export');

        $response = $this->controller->exportSepa(
            $this->request(['current_password' => 'hunter2']),
            new Response(),
            ['id' => 's-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('private_key', $this->decode($response)['messages']);
    }

    /**
     * A key from a different club decrypts nothing, so this is a bad input
     * rather than a conflict with server state.
     */
    public function test_a_key_that_does_not_match_the_active_one_is_a_422(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->encryptionKeyService->method('withActivePrivateKey')
            ->willThrowException(new \InvalidArgumentException('The supplied private key does not match key "2026-key".'));
        $this->settlementsService->expects($this->never())->method('markExported');

        $response = $this->controller->exportSepa($this->request($this->validBody()), new Response(), ['id' => 's-1']);
        $body = $this->decode($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('private_key_mismatch', $body['error']);
        $this->assertStringContainsString('does not match', $body['message']);
    }

    /**
     * An expired cryptoperiod stops the export until the admin rotates. The
     * exception carries the 409 itself, so it travels to the shared handler
     * rather than being flattened here.
     */
    public function test_an_expired_key_is_left_to_surface_as_a_409(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->encryptionKeyService->method('withActivePrivateKey')
            ->willThrowException(new EncryptionKeyExpiredException('Rotate the encryption key before creating another SEPA export'));

        $this->expectException(EncryptionKeyExpiredException::class);

        $this->controller->exportSepa($this->request($this->validBody()), new Response(), ['id' => 's-1']);
    }

    public function test_a_valid_key_produces_the_file_and_marks_the_settlement_exported(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->keyServiceOpensWith('DE89370400440532013000');
        $this->sepaExportService->method('export')->willReturn($this->exportResult());
        $this->settlementsService->expects($this->once())->method('markExported');

        $response = $this->controller->exportSepa($this->request($this->validBody()), new Response(), ['id' => 's-1']);
        $response->getBody()->rewind();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<Document/>', (string) $response->getBody());
        $this->assertSame('application/xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    /** The body is a list of plaintext IBANs; no cache may keep it. */
    public function test_the_response_is_not_storable(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->keyServiceOpensWith('DE89370400440532013000');
        $this->sepaExportService->method('export')->willReturn($this->exportResult());

        $response = $this->controller->exportSepa($this->request($this->validBody()), new Response(), ['id' => 's-1']);

        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /** The count and the settlement, never an IBAN and never the XML. */
    public function test_the_decryption_is_audited_without_any_account_data(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->keyServiceOpensWith('DE89370400440532013000');
        $this->sepaExportService->method('export')->willReturn($this->exportResult());

        $logged = [];
        $this->auditService->method('log')->willReturnCallback(
            function (...$args) use (&$logged) { $logged[] = $args; return null; }
        );

        $this->controller->exportSepa($this->request($this->validBody()), new Response(), ['id' => 's-1']);

        $exportEntries = array_values(array_filter(
            $logged,
            fn(array $args) => ($args[0] ?? null) === AuditAction::SEPA_EXPORT,
        ));

        $this->assertCount(1, $exportEntries, 'the bulk decryption must leave exactly one SEPA_EXPORT entry');

        $encoded = json_encode($exportEntries[0]);
        $this->assertStringContainsString('"collected_members":3', $encoded);
        $this->assertStringNotContainsString('DE89370400440532013000', $encoded);
        $this->assertStringNotContainsString('<Document', $encoded);
    }
}
