<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Mail\MailTransportStatus;
use App\Shared\Mail\MailTransportFactory;
use App\Shared\Services\AuditService;

/**
 * The club-editable half of mail configuration (ADR-0038 rule 2).
 *
 * It also owns the pairing of the two halves for the self-check: the DSN says
 * *whether mail can leave the host*, this table says *who it comes from*, and
 * either one missing means nothing useful is sent. Reporting them together is
 * the only way that reads as one answer instead of two amber rows.
 */
class MailConfigService
{
    public function __construct(
        private MailConfigRepository $mailConfigRepository,
        private InstanceConfigService $instanceConfigService,
        private MailTransportFactory $mailTransportFactory,
        private AuditService $auditService,
    ) {}

    public function getConfig(): MailConfigDto
    {
        $config = $this->mailConfigRepository->getConfig() ?? [];

        // The club name is already configured once, for the admin UI and the
        // TOTP issuer (ADR-0034). Defaulting the footer to it keeps a fresh
        // install from having to say the same thing twice.
        if (trim((string) ($config['footer_org_name'] ?? '')) === '') {
            $config['footer_org_name'] = $this->instanceConfigService->getInstanceName();
        }
        if (trim((string) ($config['sender_name'] ?? '')) === '') {
            $config['sender_name'] = $config['footer_org_name'];
        }

        return MailConfigDto::fromRow($config);
    }

    public function updateConfig(array $attributes, string $adminUserId): ?MailConfigDto
    {
        $old = $this->mailConfigRepository->getConfig();
        $config = $this->mailConfigRepository->updateConfig($attributes, $adminUserId);

        if (!$config) {
            return null;
        }

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::MAIL_CONFIG,
            entityId: '1',
            oldValues: $old ? self::auditable($old) : null,
            newValues: self::auditable($config),
            adminUserId: $adminUserId,
        );

        return $this->getConfig();
    }

    public function transportStatus(): MailTransportStatus
    {
        return $this->mailTransportFactory->status();
    }

    /**
     * Is this installation able to send an announcement at all?
     *
     * Both halves have to be there: a transport to carry it and a sender to
     * put on it.
     */
    public function canSend(): bool
    {
        return $this->transportStatus()->valid && $this->getConfig()->isComplete();
    }

    /** @return array<string,mixed> */
    private static function auditable(array $row): array
    {
        return array_intersect_key($row, array_flip(MailConfigRepository::UPDATABLE_COLUMNS));
    }
}
