<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\BankCodes\Controllers;

use App\Modules\BankCodes\Controllers\AdminController;
use App\Modules\BankCodes\Services\BankCodeService;
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
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(BankCodeService::class);
        $this->controller = new AdminController($this->service);
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
            ->method('lookupByIban')
            ->with('DE89370400440532013000')
            ->willReturn([
                'bank_code' => '37040044',
                'bank_name' => 'Commerzbank',
                'short_name' => null,
                'bic' => 'COBADEFFXXX',
                'postal_code' => null,
                'city' => null,
            ]);

        $response = $this->controller->lookup($this->get(['iban' => 'DE89370400440532013000']), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('COBADEFFXXX', $this->decode($response)['bic']);
    }

    public function test_lookup_explains_itself_for_a_country_it_does_not_cover(): void
    {
        $this->service->method('lookupByIban')->willReturn(null);

        $body = $this->decode($this->controller->lookup($this->get(['iban' => 'AT611904300234573201']), new Response()));

        $this->assertNull($body['bank_name']);
        $this->assertNull($body['bic']);
        $this->assertStringContainsString('German IBANs', $body['message']);
    }

    public function test_lookup_refuses_a_request_with_no_iban(): void
    {
        $this->service->expects($this->never())->method('lookupByIban');

        foreach ([[], ['iban' => ''], ['iban' => ['DE89']]] as $query) {
            $response = $this->controller->lookup($this->get($query), new Response());

            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame('Missing iban parameter', $this->decode($response)['error']);
        }
    }
}
