<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Instance\Repositories\InstanceConfigRepository;
use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Terminals\Controllers\PairingController;
use App\Modules\Terminals\Services\PairingService;
use App\Shared\Services\AuditService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * End to end through the real repository/DB: the 'terminal_repair' audit
 * action must actually be accepted by the audit_log.action ENUM (migration
 * 016), not just by the AuditAction PHP enum — a mismatch between the two
 * would only surface as a live PDOException, never in a fully-mocked test.
 */
class TerminalPairingAckEndpointTest extends SchemaTestCase
{
    public function test_acknowledge_writes_a_terminal_repair_audit_row_and_returns_the_instance_id(): void
    {
        $terminalId = $this->createAdminUser(); // any valid UUID works as entity_id; audit_log has no FK here
        $expectedInstanceId = $this->db->query('SELECT instance_id FROM instance_config WHERE id = 1')->fetchColumn();

        $auditService = new AuditService(new AuditLogRepository($this->db, $this->logger));
        $service = new PairingService(
            new InstanceConfigService(new InstanceConfigRepository($this->db, $this->logger), $auditService),
            $auditService,
        );
        $controller = new PairingController($service);

        try {
            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/terminal/pairing/ack')
                ->withAttribute('terminal_id', $terminalId);
            $response = $controller->acknowledge($request, new Response());
            $response->getBody()->rewind();
            $body = json_decode($response->getBody()->getContents(), true);

            $this->assertSame($expectedInstanceId, $body['instance_id']);

            $stmt = $this->db->prepare(
                "SELECT action, entity_type, entity_id, admin_user_id FROM audit_log
                  WHERE entity_id = ? AND action = 'terminal_repair'"
            );
            $stmt->execute([$terminalId]);
            $row = $stmt->fetch();

            $this->assertNotFalse($row, 'expected a terminal_repair audit_log row');
            $this->assertSame('terminal', $row['entity_type']);
            $this->assertNull($row['admin_user_id']);
        } finally {
            $this->db->prepare("DELETE FROM audit_log WHERE entity_id = ? AND action = 'terminal_repair'")
                ->execute([$terminalId]);
        }
    }
}
