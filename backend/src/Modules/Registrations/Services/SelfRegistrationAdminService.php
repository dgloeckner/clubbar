<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Services;

use App\Modules\Registrations\Documents\MandateDocumentFiller;
use App\Modules\Registrations\Documents\TemplateFetcher;
use App\Modules\Registrations\Documents\UnusableTemplateException;
use App\Modules\Registrations\Domain\PosterSecret;
use App\Modules\Registrations\DTOs\SelfRegistrationSettingsDto;
use App\Modules\Registrations\Repositories\SelfRegistrationConfigRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Logging\Logger;
use App\Shared\Security\SymmetricSecretBox;
use App\Shared\Services\AuditService;

/**
 * The club's control over its own registration surface (#783, UC-A69).
 *
 * Three things an admin owns here, and each is a credential or a promise rather
 * than a preference: the poster secret, the switch, and the URL of the document
 * an applicant is shown before anything is collected.
 *
 * ## Enabling is a gate, not a toggle
 *
 * Registration can only be switched **on** when both preconditions hold: a
 * secret exists, and a club document URL is configured. Neither is greyed out
 * in the UI — a disabled control that will not say why is a support call. Each
 * is refused by name, so the admin is told which one to fix.
 *
 * The document precondition is the Art. 13 one (ADR-0052 decision 6): without a
 * document there is nothing to show somebody before taking their name, their
 * birth date and their IBAN. The submission endpoint enforces it independently,
 * because a database restore or a hand-edited row would otherwise walk straight
 * past this class.
 */
class SelfRegistrationAdminService
{
    public function __construct(
        private SelfRegistrationConfigRepository $config,
        private SepaConfigRepository $sepaConfig,
        private TemplateFetcher $fetcher,
        private MandateDocumentFiller $filler,
        private SymmetricSecretBox $secretBox,
        private AuditService $audit,
        private Logger $logger,
    ) {}

    /** Everything the settings screen needs, and no secret material. */
    public function settings(): SelfRegistrationSettingsDto
    {
        $config = $this->config->get();

        return new SelfRegistrationSettingsDto(
            enabled: $config->enabled,
            disabledReason: $config->disabledReason,
            hasSecret: $config->hasSecret(),
            secretRotatedAt: $config->secretRotatedAt,
            documentUrl: $this->documentUrl() ?: null,
            retentionDays: $config->retentionDays,
        );
    }

    /**
     * Mint a poster secret, replacing any existing one.
     *
     * Returned in the clear **once**, to the admin who asked, because the whole
     * point is to print it. It is also kept sealed, so the same poster can be
     * reprinted later without rotating — losing a printout must not invalidate
     * the one already on the clubhouse wall.
     *
     * @return string the raw secret, for the caller to render into a poster
     */
    public function rotateSecret(?string $adminUserId): string
    {
        $secret = PosterSecret::mint();

        $this->config->replaceSecret(
            PosterSecret::hash($secret),
            $this->secretBox->encrypt($secret),
            $adminUserId,
        );

        // No secret material in the payload, the same rule the key and token
        // lifecycle entries follow: what is recorded is that a rotation
        // happened, by whom, and when — never the value.
        $this->audit->log(
            action: AuditAction::REGISTRATION_SECRET_ROTATED,
            entityType: EntityType::SELF_REGISTRATION,
            entityId: 'self_registration_config',
            adminUserId: $adminUserId,
        );

        $this->logger->info('Self-registration poster secret rotated');

        return $secret;
    }

    /**
     * The current secret, for reprinting a poster.
     *
     * Reading it back is the point of storing the sealed copy, and it is the
     * reason rotation and reprinting are separate actions: a club that had to
     * rotate in order to reprint would invalidate every poster in the building
     * every time somebody spilled a drink on one.
     *
     * @throws BusinessRuleException there is no secret to reprint
     */
    public function currentSecret(): string
    {
        $config = $this->config->get();

        if (!$config->hasSecret() || $config->secretCipher === null) {
            throw new BusinessRuleException(
                BusinessRuleReason::REGISTRATION_NO_SECRET,
                'No poster secret has been generated yet.',
            );
        }

        $secret = $this->secretBox->decrypt($config->secretCipher);
        if ($secret === false) {
            // The key changed, or the row was written by another installation.
            // Rotating is the only way out, and saying so beats "decryption
            // failed" — which tells an admin nothing they can act on.
            throw new BusinessRuleException(
                BusinessRuleReason::REGISTRATION_SECRET_UNREADABLE,
                'The stored poster secret cannot be read with the current key; generate a new one.',
            );
        }

        return $secret;
    }

    /**
     * Switch registration on or off.
     *
     * @param ?string $disabledReason the club's own words, shown to whoever
     *        scans the poster while it is off. Required when disabling: a blank
     *        wall reads as a broken feature, and the person reading it is
     *        standing in the clubhouse (decision 1)
     *
     * @throws BusinessRuleException a precondition for enabling does not hold
     */
    public function setAvailability(bool $enabled, ?string $disabledReason, ?string $adminUserId): void
    {
        $before = $this->config->get();

        if ($enabled) {
            $this->assertCanEnable($before->hasSecret());
        } elseif ($disabledReason === null || trim($disabledReason) === '') {
            throw new BusinessRuleException(
                BusinessRuleReason::REGISTRATION_REASON_REQUIRED,
                'Switching self-registration off requires a reason to show the people it affects.',
            );
        }

        $reason = $enabled ? null : trim((string) $disabledReason);
        $this->config->setAvailability($enabled, $reason, $adminUserId);

        $this->audit->log(
            action: $enabled ? AuditAction::REGISTRATION_ENABLED : AuditAction::REGISTRATION_DISABLED,
            entityType: EntityType::SELF_REGISTRATION,
            entityId: 'self_registration_config',
            oldValues: ['enabled' => $before->enabled],
            // The reason is in the entry because it is the club's public
            // statement, and "who said what to the members, and when" is a
            // question the log should be able to answer.
            newValues: ['enabled' => $enabled] + ($reason === null ? [] : ['reason' => $reason]),
            adminUserId: $adminUserId,
        );
    }

    /**
     * Point the club at its published Anmeldung, checking it first.
     *
     * **Validated at save time, and that is the whole change.** The column has
     * existed since migration `028` and has never been checked; a URL that is
     * wrong today surfaces as a member's registration silently arriving without
     * a document, weeks later, with nothing connecting the two. Fetched once
     * here instead, and refused by name: the field it is missing, or the rebuild
     * flag it needs.
     *
     * @throws UnusableTemplateException|BusinessRuleException
     */
    public function setDocumentUrl(string $url, ?string $adminUserId): void
    {
        $url = trim($url);
        $before = $this->documentUrl();

        if ($url !== '') {
            $template = $this->fetcher->fetch($url);
            if ($template === null) {
                throw new BusinessRuleException(
                    BusinessRuleReason::DOCUMENT_TEMPLATE_UNREACHABLE,
                    'The club document could not be fetched from that address.',
                    ['url' => $url],
                );
            }

            // Throws with the actionable reason. Nothing is written when it
            // does: a saved-but-unusable URL is the state this check exists to
            // make unreachable.
            $this->filler->assertUsable($template);
        }

        $this->sepaConfig->updateConfig(['mandate_template_url' => $url === '' ? null : $url]);

        // Switching off follows a cleared URL, rather than being left to the
        // submission endpoint to refuse one applicant at a time. The two are
        // the same decision — decision 6 makes the document a precondition of
        // collecting anything — and a club that reads as "on" while refusing
        // everybody is the state that generates the support call.
        if ($url === '' && $this->config->get()->enabled) {
            $this->config->setAvailability(false, null, $adminUserId);
            $this->logger->warning('Self-registration switched off: the club document URL was cleared');
        }

        $this->audit->log(
            action: AuditAction::REGISTRATION_DOCUMENT_URL_CHANGED,
            entityType: EntityType::SELF_REGISTRATION,
            entityId: 'self_registration_config',
            oldValues: ['document_url' => $before],
            newValues: ['document_url' => $url],
            adminUserId: $adminUserId,
        );
    }

    /**
     * @throws BusinessRuleException naming the precondition that does not hold
     */
    private function assertCanEnable(bool $hasSecret): void
    {
        if (!$hasSecret) {
            throw new BusinessRuleException(
                BusinessRuleReason::REGISTRATION_NO_SECRET,
                'Generate a poster secret before switching self-registration on.',
            );
        }

        if ($this->documentUrl() === '') {
            throw new BusinessRuleException(
                BusinessRuleReason::DOCUMENT_URL_MISSING,
                'Configure the club document URL before switching self-registration on.',
            );
        }
    }

    private function documentUrl(): string
    {
        return (string) ($this->sepaConfig->getConfig()['mandate_template_url'] ?? '');
    }
}
