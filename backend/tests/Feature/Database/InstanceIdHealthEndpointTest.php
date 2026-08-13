<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Instance\Repositories\InstanceConfigRepository;
use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\Controllers\HealthController;
use App\Shared\Services\AuditService;
use App\Shared\Services\HealthCheckService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * GET /health is the only channel a Terminal has to learn instance_id
 * (ADR-0035) — it is polled every sync cycle regardless of auth state. This
 * wires the real repository (talking to the real instance_config row)
 * through the real service and controller, so it catches a wrong column
 * name or a broken toArray() mapping that a fully-mocked unit test cannot.
 */
class InstanceIdHealthEndpointTest extends SchemaTestCase
{
    public function test_health_response_includes_the_real_instance_id(): void
    {
        $expected = $this->db->query('SELECT instance_id FROM instance_config WHERE id = 1')->fetchColumn();

        $repository = new InstanceConfigRepository($this->db, $this->logger);
        $auditService = $this->createMock(AuditService::class);
        $service = new HealthCheckService(new InstanceConfigService($repository, $auditService));
        $controller = new HealthController($service);

        $response = $controller->check(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/health'),
            new Response(),
        );
        $response->getBody()->rewind();
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertNotNull($expected);
        $this->assertSame($expected, $body['instance_id']);
    }
}
