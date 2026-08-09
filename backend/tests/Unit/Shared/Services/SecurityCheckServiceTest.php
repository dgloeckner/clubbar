<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Services;

use App\Shared\Config\AppConfig;
use App\Shared\Config\Env;
use App\Shared\DTOs\SecurityReportDto;
use App\Shared\Security\SecurityFinding;
use App\Shared\Services\SecurityCheckService;
use PHPUnit\Framework\TestCase;

/**
 * The service's job is to describe *this* deployment to the engine — the
 * document root the webserver serves, the data directory in use, the scheme the
 * request arrived on — and to turn the rows into a verdict.
 *
 * Getting that description wrong is the failure mode worth testing: a report
 * measured against the wrong document root would call a mandate safe because it
 * looked for it somewhere nothing is served from.
 */
class SecurityCheckServiceTest extends TestCase
{
    private array $envBackup = [];
    private string $documentRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = $_ENV;
        $this->documentRoot = sys_get_temp_dir() . '/clubbar-service-' . bin2hex(random_bytes(6));
        mkdir($this->documentRoot . '/backend/storage', 0700, true);
        mkdir($this->documentRoot . '/backend/logs', 0700, true);

        // The layout the shared-hosting package ships in: data inside the
        // document root, behind the `^backend/` denial.
        $_ENV['DATA_DIR'] = $this->documentRoot . '/backend';
        Env::reset();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        Env::reset();
        $this->removeTree($this->documentRoot);

        parent::tearDown();
    }

    public function test_it_reports_the_data_directory_this_deployment_uses(): void
    {
        $report = $this->check(['DOCUMENT_ROOT' => $this->documentRoot]);

        $this->assertSame(
            $this->documentRoot . '/backend (inside the document root)',
            $this->observed($report, 'data_directory')
        );
    }

    public function test_it_falls_back_to_the_front_controllers_directory(): void
    {
        // A SAPI that leaves DOCUMENT_ROOT empty still has to be measured
        // against something — and in the shared-hosting package the front
        // controller's own directory *is* the document root.
        $report = $this->check(['SCRIPT_FILENAME' => $this->documentRoot . '/index.php']);

        $this->assertStringContainsString('inside the document root', $this->observed($report, 'data_directory'));
    }

    public function test_the_transport_rows_follow_the_request_that_asked(): void
    {
        $overHttp = $this->check(['DOCUMENT_ROOT' => $this->documentRoot]);
        $this->assertSame(SecurityFinding::WARN, $this->finding($overHttp, 'https')->status);

        $overTls = $this->check(['DOCUMENT_ROOT' => $this->documentRoot, 'HTTPS' => 'on']);
        $this->assertSame(SecurityFinding::PASS, $this->finding($overTls, 'https')->status);
    }

    public function test_a_deployment_without_a_config_file_reports_no_row_about_one(): void
    {
        // A development checkout is configured through the environment. A row
        // about the permissions of a file that does not exist is noise.
        $report = $this->check(['DOCUMENT_ROOT' => $this->documentRoot]);

        $this->assertNull($this->findOrNull($report, 'config_file_mode'));
    }

    public function test_the_configuration_file_is_measured_when_the_installation_has_one(): void
    {
        file_put_contents($this->documentRoot . '/config.php', "<?php return [];\n");
        chmod($this->documentRoot . '/config.php', 0600);

        $report = $this->check(['DOCUMENT_ROOT' => $this->documentRoot]);

        $this->assertSame('0600', $this->observed($report, 'config_file_mode'));
    }

    public function test_the_verdict_is_the_worst_row_and_counts_unknown_as_a_warning(): void
    {
        $report = new SecurityReportDto('2026-08-09T10:00:00Z', [], [
            SecurityFinding::PASS => 3,
            SecurityFinding::WARN => 0,
            SecurityFinding::FAIL => 0,
            SecurityFinding::UNKNOWN => 0,
        ]);
        $this->assertSame(SecurityFinding::PASS, $report->status());

        // A check that could not be run is never evidence that its subject is
        // fine, so it cannot leave the report green.
        $report = new SecurityReportDto('2026-08-09T10:00:00Z', [], [
            SecurityFinding::PASS => 3,
            SecurityFinding::WARN => 0,
            SecurityFinding::FAIL => 0,
            SecurityFinding::UNKNOWN => 1,
        ]);
        $this->assertSame(SecurityFinding::WARN, $report->status());

        $report = new SecurityReportDto('2026-08-09T10:00:00Z', [], [
            SecurityFinding::PASS => 3,
            SecurityFinding::WARN => 2,
            SecurityFinding::FAIL => 1,
            SecurityFinding::UNKNOWN => 0,
        ]);
        $this->assertSame(SecurityFinding::FAIL, $report->status());
    }

    public function test_the_serialized_report_carries_a_timestamp_and_every_row(): void
    {
        $body = $this->check(['DOCUMENT_ROOT' => $this->documentRoot])->toArray();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['generated_at']);
        $this->assertSame(count($body['findings']), array_sum($body['summary']));
        $this->assertArrayHasKey('remedy', $body['findings'][0]);
    }

    /** @param array<string,mixed> $serverParams */
    private function check(array $serverParams): SecurityReportDto
    {
        return (new SecurityCheckService(new AppConfig()))->check($serverParams);
    }

    private function observed(SecurityReportDto $report, string $id): string
    {
        return $this->finding($report, $id)->observed;
    }

    private function finding(SecurityReportDto $report, string $id): SecurityFinding
    {
        $finding = $this->findOrNull($report, $id);
        $this->assertNotNull($finding, "No finding with id {$id}");

        return $finding;
    }

    private function findOrNull(SecurityReportDto $report, string $id): ?SecurityFinding
    {
        foreach ($report->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        return null;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "{$directory}/{$entry}";
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
