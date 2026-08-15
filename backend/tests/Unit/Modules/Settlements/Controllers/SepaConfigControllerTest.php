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
            mandateTemplateUrl: 'https://club.example/anmeldung',
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

    /**
     * #360/#456: not required at save time — a club can configure creditor
     * details before the externally hosted form exists. SepaExportService
     * (not this controller) is what refuses to export until it is set.
     */
    public function test_update_passes_the_mandate_template_url_to_the_service(): void
    {
        $this->service->expects($this->once())
            ->method('updateConfig')
            ->with(
                $this->callback(fn (array $attributes) => ($attributes['mandate_template_url'] ?? null) === 'https://club.example/anmeldung'),
                'admin-1',
            )
            ->willReturn($this->dto());

        $response = $this->controller->update(
            $this->write('PATCH', $this->validBody(['mandate_template_url' => 'https://club.example/anmeldung'])),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_update_rejects_a_mandate_template_url_longer_than_the_column(): void
    {
        $this->service->expects($this->never())->method('updateConfig');

        $response = $this->controller->update(
            $this->write('PATCH', $this->validBody(['mandate_template_url' => str_repeat('x', 256)])),
            new Response(),
        );

        $body = $this->decode($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('mandate_template_url', $body['messages']);
    }

    public function test_initial_setup_does_not_require_a_mandate_template_url(): void
    {
        $this->service->expects($this->once())
            ->method('updateConfig')
            ->willReturn($this->dto());

        $response = $this->controller->update(
            $this->write('POST', $this->validBody(['creditor_id' => 'DE98ZZZ09999999999'])),
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

    /**
     * Overwrite-only creditor IBAN (#392).
     *
     * The GET is masked, so the settings form has nothing to prefill the field
     * with. Every save that did not deliberately retype the IBAN therefore sends
     * it blank, and blank has to mean "keep" — otherwise renaming the creditor
     * would silently empty the account the collection is paid into.
     */
    public function test_show_masks_the_creditor_iban(): void
    {
        $this->service->expects($this->once())
            ->method('getConfig')
            ->with(true)
            ->willReturn(new SepaConfigDto(
                creditorId: 'DE98****9999',
                creditorName: 'Musterverein e.V.',
                creditorIban: 'DE89****3000',
                creditorAddressStreet: 'Vereinsweg 1',
                creditorAddressCity: 'Musterstadt',
                creditorAddressCountry: 'DE',
                paymentReferencePrefix: 'Club Bar',
                mandateTemplateUrl: 'https://club.example/anmeldung',
                isConfigured: true,
            ));

        $response = $this->controller->show($this->write('GET', []), new Response());
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('DE89370400440532013000', json_encode($body));
        $this->assertSame('DE89****3000', $body['creditor_iban']);
    }

    public function test_an_omitted_creditor_iban_keeps_the_stored_one(): void
    {
        $this->service->method('getConfig')->willReturn($this->dto());

        $this->service->expects($this->once())
            ->method('updateConfig')
            ->with(
                $this->callback(fn (array $attributes) => !array_key_exists('creditor_iban', $attributes)),
                'admin-1',
            )
            ->willReturn($this->dto());

        $response = $this->controller->update(
            $this->write('PATCH', ['creditor_name' => 'Neuer Name e.V.']),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_blank_creditor_iban_keeps_the_stored_one(): void
    {
        $this->service->method('getConfig')->willReturn($this->dto());

        $this->service->expects($this->once())
            ->method('updateConfig')
            ->with(
                $this->callback(fn (array $attributes) => !array_key_exists('creditor_iban', $attributes)),
                'admin-1',
            )
            ->willReturn($this->dto());

        $response = $this->controller->update(
            $this->write('PATCH', ['creditor_name' => 'Musterverein e.V.', 'creditor_iban' => '']),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_masked_creditor_iban_is_refused_with_an_actionable_message(): void
    {
        $this->service->method('getConfig')->willReturn($this->dto());
        $this->service->expects($this->never())->method('updateConfig');

        $response = $this->controller->update(
            $this->write('PATCH', $this->validBody(['creditor_iban' => 'DE89****3000'])),
            new Response(),
        );

        $body = $this->decode($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $body['error']);
        $this->assertStringContainsString('empty to keep', $body['messages']['creditor_iban'][0]);
    }

    public function test_a_filled_creditor_iban_still_replaces_the_stored_one(): void
    {
        $this->service->method('getConfig')->willReturn($this->dto());

        $this->service->expects($this->once())
            ->method('updateConfig')
            ->with(
                $this->callback(fn (array $attributes) => ($attributes['creditor_iban'] ?? null) === 'DE02120300000000202051'),
                'admin-1',
            )
            ->willReturn($this->dto());

        $response = $this->controller->update(
            $this->write('PATCH', $this->validBody(['creditor_iban' => 'DE02120300000000202051'])),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_blank_creditor_iban_is_still_refused_when_nothing_is_stored_yet(): void
    {
        $this->service->method('getConfig')->willReturn(null);
        $this->service->expects($this->never())->method('updateConfig');

        $response = $this->controller->update(
            $this->write('POST', ['creditor_id' => 'DE98ZZZ09999999999', 'creditor_name' => 'X e.V.', 'creditor_iban' => '']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('creditor_iban', $this->decode($response)['messages']);
    }
}
