<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Instance\Controllers;

use App\Modules\Instance\Controllers\InstanceConfigController;
use App\Modules\Instance\DTOs\InstanceConfigDto;
use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class InstanceConfigControllerTest extends TestCase
{
    private InstanceConfigService $service;
    private InstanceConfigController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(InstanceConfigService::class);

        $this->controller = new InstanceConfigController(
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
            ->createServerRequest($method, '/api/admin/instance-config')
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

    // ── show ────────────────────────────────────

    public function test_show_returns_the_current_instance_name(): void
    {
        $this->service->method('getConfig')->willReturn(new InstanceConfigDto(instanceName: 'FRGS Ruderbar', timeZone: 'Europe/Berlin', timeZoneSource: 'configured'));

        $response = $this->controller->show($this->write('GET', []), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('FRGS Ruderbar', $this->decode($response)['instance_name']);
    }

    // ── update ────────────────────────────────────

    public function test_update_passes_the_instance_name_to_the_service(): void
    {
        $this->service->expects($this->once())
            ->method('updateConfig')
            ->with(['instance_name' => 'FRGS Ruderbar'], 'admin-1')
            ->willReturn(new InstanceConfigDto(instanceName: 'FRGS Ruderbar', timeZone: 'Europe/Berlin', timeZoneSource: 'configured'));

        $response = $this->controller->update(
            $this->write('PATCH', ['instance_name' => 'FRGS Ruderbar']),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('FRGS Ruderbar', $this->decode($response)['instance_name']);
    }

    public function test_update_rejects_an_empty_name(): void
    {
        $this->service->expects($this->never())->method('updateConfig');

        $response = $this->controller->update($this->write('PATCH', ['instance_name' => '']), new Response());

        $body = $this->decode($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('instance_name', $body['messages']);
    }

    public function test_update_rejects_a_name_longer_than_the_column(): void
    {
        $this->service->expects($this->never())->method('updateConfig');

        $response = $this->controller->update(
            $this->write('PATCH', ['instance_name' => str_repeat('x', 101)]),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('instance_name', $this->decode($response)['messages']);
    }

    public function test_update_allows_a_name_of_exactly_one_hundred_characters(): void
    {
        $this->service->expects($this->once())
            ->method('updateConfig')
            ->willReturn(new InstanceConfigDto(instanceName: str_repeat('x', 100), timeZone: 'Europe/Berlin', timeZoneSource: 'configured'));

        $response = $this->controller->update(
            $this->write('PATCH', ['instance_name' => str_repeat('x', 100)]),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_update_returns_500_when_the_service_reports_no_row(): void
    {
        $this->service->method('updateConfig')->willReturn(null);

        $response = $this->controller->update(
            $this->write('PATCH', ['instance_name' => 'FRGS Ruderbar']),
            new Response(),
        );

        $this->assertSame(500, $response->getStatusCode());
    }
}
