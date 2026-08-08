<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Controllers;

use App\Modules\Settlements\Controllers\SepaConfigController;
use App\Modules\Settlements\DTOs\SepaConfigDto;
use App\Modules\Settlements\Services\SepaConfigService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The SEPA config write endpoint (#90).
 *
 * The payment reference prefix appears on member bank statements. It is
 * editable in the admin panel, so an edit has to reach the service — and a
 * prefix longer than the column has to come back as a validation error rather
 * than a database failure.
 */
class SepaConfigControllerTest extends TestCase
{
    private SepaConfigService $service;
    private SepaConfigController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(SepaConfigService::class);

        $this->controller = new SepaConfigController(
            $this->service,
            new Validator($this->createMock(\PDO::class)),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function write(string $method, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/admin/sepa-config')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    private function dto(?string $paymentReferencePrefix = 'Club Bar'): SepaConfigDto
    {
        return new SepaConfigDto(
            creditorId: 'DE98ZZZ09999999999',
            creditorName: 'Musterverein e.V.',
            creditorIban: 'DE89370400440532013000',
            creditorAddressStreet: 'Vereinsweg 1',
            creditorAddressCity: 'Musterstadt',
            creditorAddressCountry: 'DE',
            paymentReferencePrefix: $paymentReferencePrefix,
            isConfigured: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'creditor_name' => 'Musterverein e.V.',
            'creditor_iban' => 'DE89370400440532013000',
        ], $overrides);
    }

    public function test_update_passes_the_payment_reference_prefix_to_the_service(): void
    {
        $this->service->expects($this->once())
            ->method('updateConfig')
            ->with(
                $this->callback(fn (array $attributes) => ($attributes['payment_reference_prefix'] ?? null) === 'Club Bar'),
                'admin-1',
            )
            ->willReturn($this->dto());

        $response = $this->controller->update(
            $this->write('PATCH', $this->validBody(['payment_reference_prefix' => 'Club Bar'])),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Club Bar', $this->decode($response)['payment_reference_prefix']);
    }

    public function test_update_accepts_an_empty_prefix_so_it_can_be_cleared(): void
    {
        $this->service->expects($this->once())
            ->method('updateConfig')
            ->willReturn($this->dto(paymentReferencePrefix: ''));

        $response = $this->controller->update(
            $this->write('PATCH', $this->validBody(['payment_reference_prefix' => ''])),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_update_rejects_a_prefix_longer_than_the_column(): void
    {
        $this->service->expects($this->never())->method('updateConfig');

        $response = $this->controller->update(
            $this->write('PATCH', $this->validBody(['payment_reference_prefix' => str_repeat('x', 101)])),
            new Response(),
        );

        $body = $this->decode($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('payment_reference_prefix', $body['messages']);
    }

    public function test_update_allows_a_prefix_of_exactly_one_hundred_characters(): void
    {
        $this->service->expects($this->once())
            ->method('updateConfig')
            ->willReturn($this->dto());

        $response = $this->controller->update(
            $this->write('PATCH', $this->validBody(['payment_reference_prefix' => str_repeat('x', 100)])),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_initial_setup_requires_a_creditor_id(): void
    {
        $this->service->expects($this->never())->method('updateConfig');

        $response = $this->controller->update($this->write('POST', $this->validBody()), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('creditor_id', $this->decode($response)['messages']);
    }

    public function test_a_later_edit_does_not_require_a_creditor_id(): void
    {
        $this->service->expects($this->once())
            ->method('updateConfig')
            ->willReturn($this->dto());

        $response = $this->controller->update($this->write('PATCH', $this->validBody()), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }
}
