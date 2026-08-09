<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Controllers\AdminController;
use App\Modules\Dashboard\DTOs\DashboardDto;
use App\Modules\Dashboard\Services\DashboardService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * All the dashboard controller is still allowed to do (#118): route the
 * request, refuse a month it cannot parse, and hand back what the service says.
 */
class AdminControllerTest extends TestCase
{
    private DashboardService $service;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(DashboardService::class);
        $this->controller = new AdminController($this->service);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(string $path, array $query = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
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

    public function test_show_returns_what_the_service_assembled(): void
    {
        $this->service->method('getDashboard')->willReturn(new DashboardDto(
            metrics: ['active_members' => 7],
            recentTransactions: [],
            terminalStatus: [],
            systemStatus: ['database_health' => 'ok'],
            alerts: [],
        ));

        $response = $this->controller->show($this->get('/api/admin/dashboard'), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(7, $this->decode($response)['metrics']['active_members']);
    }

    public function test_monthly_stats_defaults_to_the_current_month(): void
    {
        $this->service->expects($this->once())
            ->method('getMonthlyStats')
            ->with(date('Y-m'))
            ->willReturn([]);

        $this->controller->monthlyStats($this->get('/api/admin/statistics/monthly'), new Response());
    }

    public function test_monthly_stats_passes_a_well_formed_month_through(): void
    {
        $this->service->expects($this->once())
            ->method('getMonthlyStats')
            ->with('2026-02')
            ->willReturn(['month' => '2026-02']);

        $response = $this->controller->monthlyStats(
            $this->get('/api/admin/statistics/monthly', ['month' => '2026-02']),
            new Response(),
        );

        $this->assertSame('2026-02', $this->decode($response)['month']);
    }

    /**
     * @return list<array{mixed}>
     */
    public static function malformedMonthProvider(): array
    {
        return [['2026-2'], ['2026'], ['february'], [''], ['2026-02-01'], [['2026-02']]];
    }

    #[DataProvider('malformedMonthProvider')]
    public function test_monthly_stats_refuses_a_month_it_cannot_parse(mixed $month): void
    {
        $this->service->expects($this->never())->method('getMonthlyStats');

        $response = $this->controller->monthlyStats(
            $this->get('/api/admin/statistics/monthly', ['month' => $month]),
            new Response(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid month format. Use YYYY-MM.', $this->decode($response)['error']);
    }
}
