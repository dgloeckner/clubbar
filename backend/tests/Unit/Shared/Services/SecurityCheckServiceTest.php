<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Services;

use App\Modules\Security\Services\IbanEncryptionMigrationService;
use App\Shared\Config\AppConfig;
use App\Shared\Security\SecurityFinding;
use App\Shared\Services\SecurityCheckService;
use PHPUnit\Framework\TestCase;

/**
 * The ADR-0035 backfill row of the security report: plaintext IBAN remnants
 * must stay visible until the batch encryption sealed every last one.
 */
class SecurityCheckServiceTest extends TestCase
{
    private function backfillFinding(?IbanEncryptionMigrationService $migration): SecurityFinding
    {
        $service = new SecurityCheckService(new AppConfig(), $migration);

        $report = $service->check(['DOCUMENT_ROOT' => sys_get_temp_dir()]);

        foreach ($report->findings as $finding) {
            if ($finding->id === 'iban-encryption-backfill') {
                return $finding;
            }
        }

        $this->fail('The report is missing the iban-encryption-backfill finding');
    }

    public function test_zero_plaintext_rows_is_a_pass(): void
    {
        $migration = $this->createMock(IbanEncryptionMigrationService::class);
        $migration->method('countRemaining')->willReturn(0);

        $finding = $this->backfillFinding($migration);

        $this->assertSame(SecurityFinding::PASS, $finding->status);
    }

    public function test_remaining_plaintext_rows_warn_with_the_count_and_remedy(): void
    {
        $migration = $this->createMock(IbanEncryptionMigrationService::class);
        $migration->method('countRemaining')->willReturn(7);

        $finding = $this->backfillFinding($migration);

        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertStringContainsString('7', $finding->observed);
        $this->assertStringContainsString('Encrypt existing IBANs', (string) $finding->remedy);
    }

    public function test_an_unqueryable_database_is_unknown_not_a_pass(): void
    {
        $migration = $this->createMock(IbanEncryptionMigrationService::class);
        $migration->method('countRemaining')->willThrowException(new \RuntimeException('db gone'));

        $finding = $this->backfillFinding($migration);

        $this->assertSame(SecurityFinding::UNKNOWN, $finding->status);
    }

    public function test_a_missing_migration_service_is_unknown(): void
    {
        $finding = $this->backfillFinding(null);

        $this->assertSame(SecurityFinding::UNKNOWN, $finding->status);
    }
}
