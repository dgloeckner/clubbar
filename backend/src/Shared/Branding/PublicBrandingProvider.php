<?php

declare(strict_types=1);

namespace App\Shared\Branding;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\Notifications\Repositories\MailConfigRepository;

/**
 * Reads the two singleton rows the club has already filled in for its mail.
 *
 * `instance_config.instance_name` and `mail_config.logo_url` — the identical
 * pair {@see \App\Modules\Notifications\DTOs\MailConfigDto::toBranding()} hands
 * the mail layout. Deliberately no third place to configure: a club that has
 * branded its mail has branded its onboarding page, and a second field asking
 * the same question is a second field to leave stale.
 *
 * ## Fail-soft, because of who is waiting
 *
 * Branding is decoration on a page that must still work without it. A missing
 * `mail_config` row — an installation that never opened the mail screen, a
 * restore mid-flight — answers `null` and the page renders its neutral header,
 * rather than turning an applicant's form into a 500. That is the same contract
 * {@see InstanceConfigService::getInstanceName()} already keeps for /health.
 */
final class PublicBrandingProvider
{
    public function __construct(
        private InstanceConfigService $instance,
        private MailConfigRepository $mailConfig,
    ) {}

    public function get(): PublicBranding
    {
        return new PublicBranding(
            clubName: $this->clubName(),
            logoUrl: $this->logoUrl(),
        );
    }

    private function clubName(): ?string
    {
        try {
            $name = trim($this->instance->getInstanceName());
        } catch (\Throwable) {
            return null;
        }

        return $name === '' ? null : $name;
    }

    private function logoUrl(): ?string
    {
        try {
            $row = $this->mailConfig->getConfig();
        } catch (\Throwable) {
            return null;
        }

        return PublicBranding::displayableLogo($row['logo_url'] ?? null);
    }
}
