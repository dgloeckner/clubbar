<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\DTOs\SepaConfigDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Services\AuditService;
use App\Shared\Exceptions\BusinessRuleException;

class SepaConfigService
{
    public function __construct(
        private SepaConfigRepository $sepaConfigRepository,
        private AuditService $auditService,
    ) {}

    public function getConfig(bool $masked = false): ?SepaConfigDto
    {
        $config = $this->sepaConfigRepository->getConfig();
        if (!$config) return null;
        return SepaConfigDto::fromRow($config, $masked);
    }

    public function updateConfig(array $attributes, string $adminUserId): ?SepaConfigDto
    {
        $old = $this->sepaConfigRepository->getConfig();
        $config = $this->sepaConfigRepository->updateConfig($attributes);

        if (!$config) {
            return null;
        }

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::SEPA_CONFIG,
            entityId: '1',
            oldValues: $old ? ['creditor_name' => $old['creditor_name']] : null,
            newValues: ['creditor_name' => $config['creditor_name'] ?? null],
            adminUserId: $adminUserId,
        );

        // Masked like the GET it mirrors — a save is not a reason to hand the
        // full creditor IBAN back to the browser (#392).
        return SepaConfigDto::fromRow($config, masked: true);
    }

    public function isConfigured(): bool
    {
        return $this->sepaConfigRepository->isConfigured();
    }

    public function generateMandateTemplatePdf(): string
    {
        $config = $this->getConfig();

        if (
            !$config ||
            empty($config->creditorId) ||
            empty($config->creditorName) ||
            empty($config->creditorAddressStreet) ||
            empty($config->creditorAddressCity) ||
            empty($config->creditorAddressCountry)
        ) {
            throw new BusinessRuleException(
                'SEPA configuration is incomplete. Please configure creditor details in Settings first.'
            );
        }

        $address = htmlspecialchars(
            $config->creditorAddressStreet . ' · ' . $config->creditorAddressCity . ', ' . $config->creditorAddressCountry,
            ENT_QUOTES,
            'UTF-8'
        );
        $footer = htmlspecialchars(
            $config->creditorName . ' · ' . $config->creditorAddressStreet . ' · ' . $config->creditorAddressCity,
            ENT_QUOTES,
            'UTF-8'
        );

        $templatePath = __DIR__ . '/../../../../resources/templates/sepa-mandate.html';
        $html = (string) file_get_contents($templatePath);

        $html = str_replace(
            ['{{APP_NAME}}', '{{CREDITOR}}', '{{CREDITOR_ID}}', '{{ADDRESS}}', '{{FOOTER}}'],
            [
                'Club Bar',
                htmlspecialchars($config->creditorName, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($config->creditorId, ENT_QUOTES, 'UTF-8'),
                $address,
                $footer,
            ],
            $html
        );

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Arial');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
