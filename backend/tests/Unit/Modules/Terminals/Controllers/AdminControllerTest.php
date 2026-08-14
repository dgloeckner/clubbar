<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Terminals\Controllers\AdminController;
use App\Modules\Terminals\Services\TerminalsService;
use App\Modules\Terminals\DTOs\TerminalDto;
use App\Modules\Terminals\DTOs\TerminalWithTokenDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Exceptions\InvalidQueryParameterException;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Shared\Http\ListEndpointAssertions;

/**
 * The terminals list endpoint (#119) — the seventh list shape, which spelled
 * the page `current_page` and the page count `last_page`.
 */
class AdminControllerTest extends TestCase
{
    use ListEndpointAssertions;

    private TerminalsService $service;
    private StepUpAuthService $stepUp;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(TerminalsService::class);
        $this->stepUp = $this->createMock(StepUpAuthService::class);
        $this->controller = new AdminController(
            $this->service,
            new Validator($this->createMock(\PDO::class)),
            $this->stepUp,
        );
    }

    /** A POST carrying a parsed body and an authenticated caller. */
    private function post(string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1')
            ->withAttribute('admin_user', ['id' => 'admin-1', 'email' => 'admin@example.com']);
    }

    private function terminalRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'terminal-1',
            'name' => 'Bar Terminal',
            'device_id' => 'BAR-MAIN-001',
            'is_active' => 1,
            'last_sync_at' => null,
            'last_transaction_at' => null,
            'token_issued_at' => '2026-08-09 09:00:00',
            'token_expires_at' => '2027-08-09 09:00:00',
            'pending_token_expires_at' => null,
            'created_at' => '2026-08-09 09:00:00',
            'updated_at' => '2026-08-09 09:00:00',
        ], $overrides);
    }

    private function credentials(): array
    {
        return ['current_password' => 'hunter2', 'totp_code' => '123456'];
    }

    public function test_index_answers_with_the_canonical_envelope(): void
    {
        $this->service->method('listTerminals')
            ->willReturn(new PaginatedResultDto([['id' => 't-1']], total: 3, limit: 50, offset: 0));

        $body = $this->decode($this->controller->index($this->get('/api/admin/terminals'), new Response()));

        $this->assertSame([['id' => 't-1']], $body['data']);
        $this->assertSame(['page' => 1, 'per_page' => 50, 'total' => 3, 'total_pages' => 1], $body['pagination']);
    }

    public function test_index_reports_the_page_the_caller_asked_for(): void
    {
        $this->service->method('listTerminals')
            ->willReturn(new PaginatedResultDto([], total: 25, limit: 10, offset: 10));

        $body = $this->decode($this->controller->index($this->get('/api/admin/terminals', ['page' => '2', 'per_page' => '10']), new Response()));

        $this->assertSame(['page' => 2, 'per_page' => 10, 'total' => 25, 'total_pages' => 3], $body['pagination']);
    }

    public function test_index_converts_the_is_active_filter_to_a_boolean(): void
    {
        $this->service->expects($this->once())
            ->method('listTerminals')
            ->with(50, 0, false)
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 50, offset: 0));

        $this->controller->index($this->get('/api/admin/terminals', ['is_active' => 'false']), new Response());
    }

    public function test_index_leaves_the_filter_unset_when_it_is_absent(): void
    {
        $this->service->expects($this->once())
            ->method('listTerminals')
            ->with(50, 0, null)
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 50, offset: 0));

        $this->controller->index($this->get('/api/admin/terminals'), new Response());
    }

    public function test_index_refuses_a_per_page_over_the_cap(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        $this->controller->index($this->get('/api/admin/terminals', ['per_page' => '500']), new Response());
    }

    // ─── Issuing a credential is gated on a step-up (#395) ───────────────────

    public function test_store_mints_a_token_when_the_step_up_holds(): void
    {
        $this->stepUp->method('verify')->willReturn(true);
        $this->service->expects($this->once())
            ->method('createTerminal')
            ->with('Bar Terminal', 'BAR-MAIN-001', 'admin-1')
            ->willReturn([
                'terminal' => TerminalWithTokenDto::fromRowWithToken($this->terminalRow(), 'a-plaintext-token'),
                'plaintext_token' => 'a-plaintext-token',
            ]);

        $response = $this->controller->store(
            $this->post('/api/admin/terminals', [
                'name' => 'Bar Terminal',
                'device_id' => 'BAR-MAIN-001',
            ] + $this->credentials()),
            new Response(),
        );
        $body = $this->decode($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('a-plaintext-token', $body['api_token']);
        // The token belongs at the top level, and nowhere else in the payload.
        $this->assertArrayNotHasKey('api_token', $body['terminal']);
    }

    public function test_store_refuses_a_failed_step_up_without_minting_anything(): void
    {
        $this->stepUp->method('verify')->willReturn(false);
        $this->service->expects($this->never())->method('createTerminal');

        $response = $this->controller->store(
            $this->post('/api/admin/terminals', [
                'name' => 'Bar Terminal',
                'device_id' => 'BAR-MAIN-001',
                'current_password' => 'wrong',
            ]),
            new Response(),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
    }

    /**
     * The credential is a required field, so a body without one is bad input
     * rather than a failed authentication — and the step-up is never consulted,
     * which keeps an empty submit off the login rate limiter.
     */
    public function test_store_rejects_a_body_with_no_credential_before_verifying(): void
    {
        $this->stepUp->expects($this->never())->method('verify');

        $response = $this->controller->store(
            $this->post('/api/admin/terminals', ['name' => 'Bar Terminal', 'device_id' => 'BAR-MAIN-001']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('current_password', $this->decode($response)['messages']);
    }

    public function test_store_reports_a_duplicate_device_id_as_a_field_error(): void
    {
        $this->stepUp->method('verify')->willReturn(true);
        $this->service->method('createTerminal')
            ->willThrowException(new DuplicateResourceException('Device ID already exists'));

        $response = $this->controller->store(
            $this->post('/api/admin/terminals', [
                'name' => 'Bar Terminal',
                'device_id' => 'BAR-MAIN-001',
            ] + $this->credentials()),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('device_id', $this->decode($response)['messages']);
    }

    public function test_rotateToken_stages_a_replacement_when_the_step_up_holds(): void
    {
        $this->stepUp->method('verify')->willReturn(true);
        $this->service->expects($this->once())
            ->method('rotateToken')
            ->with('terminal-1', 'admin-1')
            ->willReturn([
                'terminal' => TerminalWithTokenDto::fromRowWithToken(
                    $this->terminalRow(['pending_token_expires_at' => '2027-08-09 09:00:00']),
                    'the-new-token',
                ),
                'plaintext_token' => 'the-new-token',
            ]);

        $response = $this->controller->rotateToken(
            $this->post('/api/admin/terminals/terminal-1/rotate-token', $this->credentials()),
            new Response(),
            ['id' => 'terminal-1'],
        );
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('the-new-token', $body['api_token']);
        $this->assertTrue($body['terminal']['has_pending_token']);
        // The operator has to be told the old token still works, or they will
        // read a still-selling terminal as a rotation that failed.
        $this->assertStringContainsString('keeps working', $body['message']);
    }

    public function test_rotateToken_refuses_a_failed_step_up_without_staging_anything(): void
    {
        $this->stepUp->method('verify')->willReturn(false);
        $this->service->expects($this->never())->method('rotateToken');

        $response = $this->controller->rotateToken(
            $this->post('/api/admin/terminals/terminal-1/rotate-token', ['current_password' => 'wrong']),
            new Response(),
            ['id' => 'terminal-1'],
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rotateToken_rejects_a_body_with_no_credential_before_verifying(): void
    {
        $this->stepUp->expects($this->never())->method('verify');

        $response = $this->controller->rotateToken(
            $this->post('/api/admin/terminals/terminal-1/rotate-token', []),
            new Response(),
            ['id' => 'terminal-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * A caller with no `admin_user` attribute cannot have proved anything, so
     * the gate must refuse rather than hand a null to the verifier.
     */
    public function test_a_request_without_an_authenticated_caller_is_refused(): void
    {
        $this->stepUp->expects($this->never())->method('verify');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/terminals/terminal-1/rotate-token')
            ->withParsedBody($this->credentials());

        $response = $this->controller->rotateToken($request, new Response(), ['id' => 'terminal-1']);

        $this->assertSame(401, $response->getStatusCode());
    }

    // ─── Revocation is deliberately not gated ────────────────────────────────

    /**
     * Withdrawing access must never be the harder path: an admin who suspects a
     * terminal is compromised should not be stopped by a password prompt.
     */
    public function test_revoke_needs_no_step_up(): void
    {
        $this->stepUp->expects($this->never())->method('verify');
        $this->service->expects($this->once())->method('revokeAccess')->with('terminal-1', 'admin-1');

        $response = $this->controller->revoke(
            $this->post('/api/admin/terminals/terminal-1/revoke', []),
            new Response(),
            ['id' => 'terminal-1'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_show_reports_the_lifecycle_tier_of_the_current_token(): void
    {
        $this->service->method('getTerminal')
            ->willReturn(TerminalDto::fromRow($this->terminalRow(['token_expires_at' => '2020-01-01 00:00:00'])));

        $body = $this->decode($this->controller->show(
            $this->get('/api/admin/terminals/terminal-1'),
            new Response(),
            ['id' => 'terminal-1'],
        ));

        $this->assertSame('expired', $body['terminal']['lifecycle_state']);
        $this->assertLessThan(0, $body['terminal']['days_until_expiry']);
    }
}
