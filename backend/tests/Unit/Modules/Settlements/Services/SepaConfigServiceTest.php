<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Services\SepaConfigService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class SepaConfigServiceTest extends TestCase
{
    private SepaConfigRepository $sepaConfigRepository;
    private AuditService $auditService;
    private SepaConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sepaConfigRepository = $this->createMock(SepaConfigRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->service = new SepaConfigService($this->sepaConfigRepository, $this->auditService);
    }

    private function configRow(array $overrides = []): array
    {
        return array_merge([
            'creditor_id' => 'DE98ZZZ09999999999',
            'creditor_name' => 'Musterverein e.V.',
            'creditor_iban' => 'DE89370400440532013000',
            'creditor_address_street' => 'Vereinsweg 1',
            'creditor_address_city' => '12345 Musterstadt',
            'creditor_address_country' => 'DE',
            'payment_reference_prefix' => 'BAR',
        ], $overrides);
    }

    // ── getConfig ────────────────────────────────────

    public function test_getConfig_returns_null_when_not_configured_yet(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn(null);

        $this->assertNull($this->service->getConfig());
    }

    public function test_getConfig_returns_unmasked_dto_by_default(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn($this->configRow());

        $result = $this->service->getConfig();

        $this->assertSame('DE98ZZZ09999999999', $result->creditorId);
        $this->assertSame('DE89370400440532013000', $result->creditorIban);
        $this->assertTrue($result->isConfigured);
    }

    public function test_getConfig_masks_creditor_id_and_iban_when_requested(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn($this->configRow());

        $result = $this->service->getConfig(masked: true);

        $this->assertSame('DE98****9999', $result->creditorId);
        $this->assertSame('DE89****3000', $result->creditorIban);
        // Non-sensitive fields are unaffected by masking
        $this->assertSame('Musterverein e.V.', $result->creditorName);
    }

    // ── updateConfig ────────────────────────────────────

    public function test_updateConfig_returns_null_when_repository_reports_no_row(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn(null);
        $this->sepaConfigRepository->method('updateConfig')->willReturn(null);

        $this->auditService->expects($this->never())->method('log');

        $result = $this->service->updateConfig(['creditor_name' => 'New Name'], 'admin-1');

        $this->assertNull($result);
    }

    public function test_updateConfig_persists_and_returns_the_updated_dto(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn($this->configRow(['creditor_name' => 'Old Name']));
        $this->sepaConfigRepository->method('updateConfig')
            ->with(['creditor_name' => 'New Name'])
            ->willReturn($this->configRow(['creditor_name' => 'New Name']));

        $result = $this->service->updateConfig(['creditor_name' => 'New Name'], 'admin-1');

        $this->assertSame('New Name', $result->creditorName);
    }

    public function test_updateConfig_writes_audit_entry_with_only_creditor_name_before_and_after(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn($this->configRow(['creditor_name' => 'Old Name']));
        $this->sepaConfigRepository->method('updateConfig')->willReturn($this->configRow(['creditor_name' => 'New Name']));

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                $this->anything(),
                $this->anything(),
                '1',
                ['creditor_name' => 'Old Name'],
                ['creditor_name' => 'New Name'],
                'admin-1',
            );

        $this->service->updateConfig(['creditor_name' => 'New Name'], 'admin-1');
    }

    public function test_updateConfig_writes_audit_entry_with_null_old_values_on_first_configuration(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn(null);
        $this->sepaConfigRepository->method('updateConfig')->willReturn($this->configRow());

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                $this->anything(),
                $this->anything(),
                '1',
                null,
                ['creditor_name' => 'Musterverein e.V.'],
                'admin-1',
            );

        $this->service->updateConfig($this->configRow(), 'admin-1');
    }

    // ── isConfigured ────────────────────────────────────

    public function test_isConfigured_delegates_to_repository(): void
    {
        $this->sepaConfigRepository->method('isConfigured')->willReturn(true);

        $this->assertTrue($this->service->isConfigured());
    }

    // ── generateMandateTemplatePdf ────────────────────────────────────

    public function test_generateMandateTemplatePdf_throws_when_not_configured(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn(null);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('SEPA configuration is incomplete');

        $this->service->generateMandateTemplatePdf();
    }

    public function test_generateMandateTemplatePdf_throws_when_creditor_address_is_missing(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn(
            $this->configRow(['creditor_address_street' => null])
        );

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('SEPA configuration is incomplete');

        $this->service->generateMandateTemplatePdf();
    }

    public function test_generateMandateTemplatePdf_renders_a_pdf_when_fully_configured(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn($this->configRow());

        $pdf = $this->service->generateMandateTemplatePdf();

        // %PDF is the magic header of every valid PDF document
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
