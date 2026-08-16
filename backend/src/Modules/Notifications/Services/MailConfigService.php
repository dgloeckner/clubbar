<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Shared\Config\AppConfig;
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
 *
 * It also owns the URL-trigger secret's precedence (#473): a rotated value in
 * `mail_config` beats `config.php`'s `cron.secret`, which is why both
 * {@see \App\Modules\Notifications\Controllers\CronController} and
 * {@see SchedulerStatusService} ask this class rather than each re-deriving
 * the same fallback logic against `AppConfig` directly.
 */
class MailConfigService
{
    public function __construct(
        private MailConfigRepository $mailConfigRepository,
        private InstanceConfigService $instanceConfigService,
        private MailTransportFactory $mailTransportFactory,
        private AuditService $auditService,
        private AppConfig $appConfig,
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
     * Is there anything the URL trigger would accept — a rotated secret, or
     * `config.php`'s? Answers "should the route be mounted at all", not "is
     * this request authorised" (see {@see verifyCronSecret()}).
     */
    public function cronSecretConfigured(): bool
    {
        return $this->getConfig()->cronSecretHash !== null || $this->appConfig->cronSecret !== null;
    }

    /**
     * Authorise a URL-trigger request against whichever source is
     * authoritative right now.
     *
     * Once a secret has been rotated from the panel, `config.php`'s stops
     * being checked at all — rotating supersedes the file value instead of
     * leaving two live credentials, the same way a terminal token retires the
     * one it replaces once the new one is used for the first time.
     */
    public function verifyCronSecret(string $provided): bool
    {
        if ($provided === '') {
            return false;
        }

        $hash = $this->getConfig()->cronSecretHash;
        if ($hash !== null) {
            return CronSecret::verify($provided, $hash);
        }

        $fileSecret = $this->appConfig->cronSecret;
        return $fileSecret !== null && hash_equals($fileSecret, $provided);
    }

    /**
     * Generate a replacement, store only its hash, and return the plaintext —
     * exactly once, to whichever caller just proved they are an admin. Nothing
     * short of the database row itself remembers it after this call returns.
     */
    public function rotateCronSecret(string $adminUserId): string
    {
        $plaintext = CronSecret::generate();
        $this->mailConfigRepository->rotateCronSecret(CronSecret::hash($plaintext), $adminUserId);

        // No oldValues/newValues: the event is the fact, and there is no
        // truthful "before" to show that is not itself a secret.
        $this->auditService->log(
            action: AuditAction::CRON_SECRET_ROTATED,
            entityType: EntityType::MAIL_CONFIG,
            entityId: '1',
            adminUserId: $adminUserId,
        );

        return $plaintext;
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
