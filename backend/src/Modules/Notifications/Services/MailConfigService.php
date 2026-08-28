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
 * It also owns the URL-trigger secret's precedence: `config.php`'s
 * `cron.secret` is where a secret is written and rotated (by the installer,
 * beside the scheduler instructions that quote it), and a hash left in
 * `mail_config` by the admin panel that used to mint one (#473, removed in
 * #744) still wins where an installation has one. Both
 * {@see \App\Modules\Notifications\Controllers\CronController} and
 * {@see SchedulerStatusService} ask this class rather than each re-deriving
 * the same fallback logic against `AppConfig` directly.
 */
class MailConfigService
{
    /**
     * `getConfig()` is called once per outbox row by every `MailContentBuilder`
     * that renders a settlement or statement message — a drain clearing a real
     * backlog calls it hundreds of times in a batch that never once changes it.
     * Cached for the lifetime of this instance, which is the lifetime of one
     * request or one CLI run (`ServiceFactory::resolve()` — a fresh instance
     * every time), so nothing here can see a different admin's write. Every
     * method below that mutates the row clears this before returning.
     */
    private ?MailConfigDto $cachedConfig = null;

    public function __construct(
        private MailConfigRepository $mailConfigRepository,
        private InstanceConfigService $instanceConfigService,
        private MailTransportFactory $mailTransportFactory,
        private AuditService $auditService,
        private AppConfig $appConfig,
    ) {}

    public function getConfig(): MailConfigDto
    {
        return $this->cachedConfig ??= $this->loadConfig();
    }

    private function loadConfig(): MailConfigDto
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

        $this->cachedConfig = null;

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
     * `config.php`'s `cron.secret` is the normal answer. A hash in
     * `mail_config` still beats it, and only an installation that rotated from
     * the old admin panel has one: while that panel existed, rotating
     * superseded the file value rather than leaving two live credentials, and
     * the scheduler such an installation set up is sending the panel's secret
     * to this day. Nothing can write that column any more — but silently
     * ignoring it would stop a working cron job, so it keeps its precedence
     * until the installer's rotation clears it (#744).
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
