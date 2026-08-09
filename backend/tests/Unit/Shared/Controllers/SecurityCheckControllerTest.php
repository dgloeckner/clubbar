<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Controllers;

use App\Shared\Controllers\SecurityCheckController;
use App\Shared\DTOs\SecurityReportDto;
use App\Shared\Security\SecurityFinding;
use App\Shared\Services\SecurityCheckService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The controller's whole job is to hand the request's own server parameters to
 * the service and serialise what comes back — the scheme the request arrived
 * on decides two rows of the report, so passing anything else would make the
 * transport findings describe a different request than the one being answered.
 */
class SecurityCheckControllerTest extends TestCase
{
    public function test_it_measures_against_the_request_that_asked(): void
    {
        $service = $this->createMock(SecurityCheckService::class);
        $service->expects($this->once())
            ->method('check')
            ->with($this->callback(fn(array $server) => ($server['HTTPS'] ?? null) === 'on'))
            ->willReturn($this->report());

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/security-check', ['HTTPS' => 'on']);

        $response = (new SecurityCheckController($service))->show($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_it_returns_the_report_as_measured(): void
    {
        $service = $this->createMock(SecurityCheckService::class);
        $service->method('check')->willReturn($this->report());

        $response = (new SecurityCheckController($service))->show(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/security-check'),
            new Response(),
        );

        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame('warn', $body['status']);
        $this->assertSame('session_strict_mode', $body['findings'][0]['id']);
        $this->assertSame('session.use_strict_mode=On', $body['findings'][0]['observed']);
        $this->assertNotNull($body['findings'][1]['remedy']);
    }

    private function report(): SecurityReportDto
    {
        $findings = [
            SecurityFinding::pass(
                'session_strict_mode',
                'session',
                'PHP refuses session IDs it did not issue',
                'session.use_strict_mode=On'
            ),
            SecurityFinding::warn('https', 'transport', 'Reached over HTTPS', 'plain HTTP', 'Enable TLS.'),
        ];

        return new SecurityReportDto(
            generatedAt: '2026-08-09T10:00:00Z',
            findings: $findings,
            summary: [
                SecurityFinding::PASS => 1,
                SecurityFinding::WARN => 1,
                SecurityFinding::FAIL => 0,
                SecurityFinding::UNKNOWN => 0,
            ],
        );
    }
}
