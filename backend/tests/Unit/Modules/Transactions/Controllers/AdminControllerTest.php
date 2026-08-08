<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transactions\Controllers;

use App\Modules\Transactions\Controllers\AdminController;
use App\Modules\Transactions\Services\TransactionsService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The storno endpoint's HTTP edge (#169).
 *
 * The point of these tests is what the controller refuses to pass on. A storno
 * takes a reason and nothing else: the amount and the member are read from the
 * transaction named in the path, so a caller cannot reach them. Anything else
 * in the body is ignored rather than rejected — there is no shape of it that
 * could be honoured.
 */
class AdminControllerTest extends TestCase
{
    private TransactionsService $transactionsService;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->transactionsService = $this->createMock(TransactionsService::class);
        // The rules used here (required, string, max) never touch the database.
        $validator = new Validator($this->createMock(\PDO::class));

        $this->controller = new AdminController($this->transactionsService, $validator);
    }

    private function request(array $body, ?string $adminId = 'admin-1'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/transactions/tx-1/storno')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', $adminId);
    }

    private function decode(Response $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    public function test_storno_returns_201_with_the_created_transaction(): void
    {
        $storno = ['id' => 'storno-1', 'amount_cents' => -350, 'transaction_type' => 'storno'];

        $this->transactionsService->expects($this->once())
            ->method('storno')
            ->with('tx-1', 'Wrong card scanned', 'admin-1')
            ->willReturn(['transaction' => $storno, 'new_balance_cents' => 0]);

        $response = $this->controller->storno(
            $this->request(['reason' => 'Wrong card scanned']),
            new Response(),
            ['transactionId' => 'tx-1'],
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame($storno, $this->decode($response));
    }

    public function test_storno_passes_the_admin_from_the_request_attribute(): void
    {
        $this->transactionsService->expects($this->once())
            ->method('storno')
            ->with('tx-9', 'Reason', null)
            ->willReturn(['transaction' => ['id' => 's'], 'new_balance_cents' => 0]);

        $this->controller->storno(
            $this->request(['reason' => 'Reason'], null),
            new Response(),
            ['transactionId' => 'tx-9'],
        );
    }

    public function test_storno_ignores_an_amount_the_caller_tries_to_supply(): void
    {
        // The signature is the guarantee: there is nowhere for amount_cents or
        // member_id to go, so they are dropped rather than 422'd.
        $this->transactionsService->expects($this->once())
            ->method('storno')
            ->with('tx-1', 'Reason', 'admin-1')
            ->willReturn(['transaction' => ['id' => 's'], 'new_balance_cents' => 0]);

        $response = $this->controller->storno(
            $this->request([
                'reason' => 'Reason',
                'amount_cents' => -99999,
                'member_id' => 'somebody-else',
                'related_transaction_id' => 'another-tx',
            ]),
            new Response(),
            ['transactionId' => 'tx-1'],
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_storno_trims_the_reason_before_storing_it(): void
    {
        $this->transactionsService->expects($this->once())
            ->method('storno')
            ->with('tx-1', 'Padded reason', 'admin-1')
            ->willReturn(['transaction' => ['id' => 's'], 'new_balance_cents' => 0]);

        $this->controller->storno(
            $this->request(['reason' => "  Padded reason\n"]),
            new Response(),
            ['transactionId' => 'tx-1'],
        );
    }

    public function test_storno_rejects_a_missing_reason_with_422(): void
    {
        $this->transactionsService->expects($this->never())->method('storno');

        $response = $this->controller->storno(
            $this->request([]),
            new Response(),
            ['transactionId' => 'tx-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('reason', $body['errors']);
    }

    public function test_storno_rejects_a_blank_reason_with_422(): void
    {
        // Whitespace passes `required` but is not a reason anyone can audit.
        $this->transactionsService->expects($this->never())->method('storno');

        $response = $this->controller->storno(
            $this->request(['reason' => '    ']),
            new Response(),
            ['transactionId' => 'tx-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('reason', $this->decode($response)['errors']);
    }

    public function test_storno_rejects_a_reason_over_500_chars_with_422(): void
    {
        $this->transactionsService->expects($this->never())->method('storno');

        $response = $this->controller->storno(
            $this->request(['reason' => str_repeat('A', 501)]),
            new Response(),
            ['transactionId' => 'tx-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('reason', $this->decode($response)['errors']);
    }

    public function test_storno_treats_an_absent_body_as_a_missing_reason(): void
    {
        $this->transactionsService->expects($this->never())->method('storno');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/transactions/tx-1/storno')
            ->withAttribute('admin_user_id', 'admin-1');

        $response = $this->controller->storno($request, new Response(), ['transactionId' => 'tx-1']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('reason', $this->decode($response)['errors']);
    }
}
