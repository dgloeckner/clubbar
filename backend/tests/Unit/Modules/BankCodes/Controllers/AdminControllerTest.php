<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\BankCodes\Controllers;

use App\Modules\BankCodes\Controllers\AdminController;
use App\Modules\BankCodes\Services\BankCodeService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The bank-lookup endpoint (#118). It used to hold a repository and assemble
 * the answer itself; it now asks the service and reports what it hears.
 */
class AdminControllerTest extends TestCase
{
    private BankCodeService $service;
    private AuditService $audit;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(BankCodeService::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->controller = new AdminController(
            $this->service,
            $this->audit,
            $this->createMock(Logger::class),
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(array $query = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/bank-lookup')
            ->withQueryParams($query);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    public function test_lookup_returns_the_bank_the_service_found(): void
    {
        $this->service->expects($this->once())
            ->method('lookupByBlz')
            ->with('37040044')
            ->willReturn([
                'bank_code' => '37040044',
                'bank_name' => 'Commerzbank',
                'short_name' => null,
                'bic' => 'COBADEFFXXX',
                'postal_code' => null,
                'city' => null,
            ]);

        $response = $this->controller->lookup($this->get(['blz' => '37040044']), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('COBADEFFXXX', $this->decode($response)['bic']);
    }

    public function test_lookup_explains_itself_for_a_malformed_blz(): void
    {
        $this->service->method('lookupByBlz')->willReturn(null);

        $body = $this->decode($this->controller->lookup($this->get(['blz' => '1904']), new Response()));

        $this->assertNull($body['bank_name']);
        $this->assertNull($body['bic']);
        $this->assertStringContainsString('8-digit German Bankleitzahl', $body['message']);
    }

    public function test_lookup_refuses_a_request_with_no_blz(): void
    {
        $this->service->expects($this->never())->method('lookupByBlz');

        foreach ([[], ['blz' => ''], ['blz' => ['37040044']]] as $query) {
            $response = $this->controller->lookup($this->get($query), new Response());

            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame('Missing blz parameter', $this->decode($response)['error']);
        }
    }

    /**
     * The one post-restore action a shared host cannot otherwise perform
     * (ADR-0049, #690).
     *
     * `bank_codes` is `SCHEMA_ONLY` in an archive — ~20k rows identical in
     * every installation would dominate every nightly backup — so a restored
     * installation comes back with the table empty, and `install.php` 403s once
     * `storage/.installed` exists while `bin/import-bank-codes.php` needs a
     * shell the reference host does not have.
     */
    public function test_reimport_refills_the_table_and_reports_what_it_did(): void
    {
        $this->service->expects($this->once())
            ->method('downloadAndImport')
            ->willReturn([
                'imported' => 20134,
                'removed' => 12,
                'total' => 20134,
                'source' => 'https://www.bundesbank.de/blz.txt',
            ]);

        $response = $this->controller->reimport($this->post(), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(20134, $this->decode($response)['imported']);
    }

    /**
     * Reaching a third party to rewrite a whole table earns a row
     * (pattern-016). The entity is the *table*: a re-import replaces all of it,
     * and naming one of ~20k bank codes as the subject would be arbitrary.
     */
    public function test_reimport_is_audited_against_the_table_and_the_admin_who_asked(): void
    {
        $this->service->method('downloadAndImport')->willReturn([
            'imported' => 3, 'removed' => 0, 'total' => 3, 'source' => 'file://fixture',
        ]);

        $this->audit->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::BANK_CODES_IMPORTED,
                EntityType::BANK_CODES,
                'bank_codes',
                null,
                $this->callback(static fn (array $new): bool => $new['imported'] === 3),
                'admin-42',
            );

        $this->controller->reimport($this->post('admin-42'), new Response());
    }

    /**
     * The Bundesbank being unreachable is not a bug in this installation, and
     * the admin can act on it — retry, or use the CLI importer if they have a
     * shell. So it says which it was, and it is a 502 rather than a 500: the
     * upstream failed, not us.
     */
    public function test_an_unreachable_bundesbank_is_a_502_that_says_so(): void
    {
        $this->service->method('downloadAndImport')
            ->willThrowException(new \RuntimeException('Connection timed out'));

        $this->audit->expects($this->never())->method('log');

        $response = $this->controller->reimport($this->post(), new Response());

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('Connection timed out', $this->decode($response)['error']);
    }

    private function post(string $adminId = 'admin-1'): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/bank-codes/reimport')
            ->withAttribute('admin_user_id', $adminId);
    }
}
