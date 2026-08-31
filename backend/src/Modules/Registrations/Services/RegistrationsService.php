<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Services;

use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\Registrations\Domain\PosterSecret;
use App\Modules\Registrations\DTOs\RegistrationReceiptDto;
use App\Modules\Registrations\Repositories\RegistrationAttemptsRepository;
use App\Modules\Registrations\Repositories\RegistrationsRepository;
use App\Modules\Registrations\Repositories\SelfRegistrationConfigRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\TooManyAttemptsException;
use App\Shared\Logging\Logger;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Utils\Uuid;

/**
 * The one place a stranger's submission becomes a stored row (ADR-0052).
 *
 * Everything about this class is shaped by who calls it: an unauthenticated
 * request from the internet, holding a secret printed on a wall. So it refuses
 * before it reads, seals before it writes, and answers the same way to
 * questions it must not answer.
 *
 * ## No personal data reaches the log from here
 *
 * A refusal logs its reason code and the meter that tripped. It does not log
 * the name, the address or the IBAN of whoever sent it — the public endpoint's
 * log is the one place personal data would accumulate outside the store that
 * has a retention rule (decision 10).
 */
class RegistrationsService
{
    /**
     * Two meters, because one is not enough.
     *
     * Refusals are metered like a password guess: whoever is probing for a
     * valid poster secret spends the same budget they would spend guessing a
     * login. Accepted submissions get their own, smaller budget, because the
     * caller this endpoint is most exposed to is not a prober at all — it is
     * somebody who has the real secret and can fill the treasurer's queue
     * without ever failing an attempt.
     */
    public const MAX_REFUSED_PER_WINDOW = 10;
    public const MAX_ACCEPTED_PER_WINDOW = 5;
    public const WINDOW_MINUTES = 15;

    /**
     * A hidden field no person ever fills.
     *
     * The traffic a public URL attracts is overwhelmingly commodity form
     * fillers, which fill everything they find. They get a response they cannot
     * tell from a success, and nothing is stored.
     */
    public const HONEYPOT_FIELD = 'website';

    public function __construct(
        private RegistrationsRepository $registrations,
        private SelfRegistrationConfigRepository $config,
        private RegistrationAttemptsRepository $attempts,
        private EncryptionKeysRepository $encryptionKeys,
        private BankCodeService $bankCodes,
        private SepaConfigRepository $sepaConfig,
        private IbanSealedBox $sealedBox,
        private Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $data already validated by the controller
     * @throws NotFoundException the uniform refusal: no secret, wrong secret,
     *         or a club that has never generated one — indistinguishable on
     *         purpose, because telling them apart confirms a secret exists
     * @throws BusinessRuleException the club has switched registration off
     * @throws TooManyAttemptsException a meter tripped — busy, not closed
     */
    public function submit(string $presentedSecret, array $data, string $ip): RegistrationReceiptDto
    {
        $this->assertWithinMeters($ip);

        $config = $this->config->get();

        if (!PosterSecret::matches($presentedSecret, $config->secretHash)) {
            $this->attempts->record($ip, RegistrationAttemptsRepository::REFUSED);
            $this->logger->info('Self-registration refused: secret did not match');

            throw new NotFoundException('Not found');
        }

        if (!$config->enabled) {
            // Not a 404: this caller demonstrably holds the club's poster, and
            // is owed the club's own explanation rather than a blank wall.
            $this->attempts->record($ip, RegistrationAttemptsRepository::REFUSED);
            $this->logger->info('Self-registration refused: currently disabled');

            throw new BusinessRuleException(
                BusinessRuleReason::REGISTRATION_DISABLED,
                'Self-registration is currently switched off.',
                ['reason' => $config->disabledReason ?? ''],
            );
        }

        // From here the caller is legitimate, so the accepted meter is what
        // governs them — recorded even for the honeypot, so a bot cannot use
        // the trap as an unmetered channel.
        $this->attempts->record($ip, RegistrationAttemptsRepository::ACCEPTED);

        if (($data[self::HONEYPOT_FIELD] ?? '') !== '') {
            $this->logger->info('Self-registration honeypot tripped');

            // A receipt shaped exactly like a real one. Nothing is stored, and
            // nothing in the response says so.
            return new RegistrationReceiptDto(
                id: Uuid::v4(),
                mandateReference: str_replace('-', '', Uuid::v4()),
            );
        }

        // The last moment the plaintext exists. Everything that needs to read
        // the IBAN happens here, inside one request: the bank lookup, the
        // fingerprint, and the seal. After this the server cannot open it again
        // (ADR-0036).
        $iban = IbanSealedBox::normalize((string) $data['iban']);
        $key = $this->encryptionKeys->requireOperationalActive();
        $bankName = $this->bankCodes->getBankNameForIban($iban);

        $submittedAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$config->retentionDays} days"));

        // Minted here, not at approval: it is printed on the paper the member
        // signs, so it has to exist before the mandate does, and approval
        // carries it across unchanged (ADR-0006, ADR-0052 decision 4).
        $mandateReference = str_replace('-', '', Uuid::v4());

        $id = $this->registrations->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'],
            'preferred_language' => $data['preferred_language'],
            'account_holder_name' => $data['account_holder_name'] ?? null,
            'mandate_reference' => $mandateReference,
            'iban_ciphertext' => $this->sealedBox->seal($iban, $key['public_key']),
            'iban_last4' => IbanSealedBox::lastFour($iban),
            'iban_fingerprint' => $this->sealedBox->fingerprint($iban),
            'encryption_key_id' => $key['id'],
            'bank_name' => $bankName,
            'privacy_notice_url' => $this->documentUrl(),
            'privacy_notice_shown_at' => $submittedAt,
            'submitted_at' => $submittedAt,
            'expires_at' => $expiresAt,
        ]);

        // No identity, here or anywhere else on this route.
        $this->logger->info('Self-registration accepted');

        return new RegistrationReceiptDto(id: $id, mandateReference: $mandateReference);
    }

    /**
     * Delete everything past its expiry, for the cron tick.
     *
     * Returns how many went. The caller logs the number and never the people.
     */
    public function purgeExpired(): int
    {
        return $this->registrations->purgeExpired(date('Y-m-d H:i:s'));
    }

    /**
     * The club's document — the one the onboarding page linked before any data
     * entry, whose later pages are the Datenschutzhinweise (decision 6).
     *
     * Recorded as shown rather than fetched: what this row needs to remember is
     * which document the applicant was pointed at.
     */
    private function documentUrl(): string
    {
        $config = $this->sepaConfig->getConfig();

        return (string) ($config['mandate_template_url'] ?? '');
    }

    /**
     * Both meters, checked before anything else — including before the secret,
     * so that a flood of guesses cannot be made cheaper by being wrong.
     */
    private function assertWithinMeters(string $ip): void
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . self::WINDOW_MINUTES . ' minutes'));

        $refused = $this->attempts->countRecent($ip, RegistrationAttemptsRepository::REFUSED, $since);
        $accepted = $this->attempts->countRecent($ip, RegistrationAttemptsRepository::ACCEPTED, $since);

        if ($refused >= self::MAX_REFUSED_PER_WINDOW || $accepted >= self::MAX_ACCEPTED_PER_WINDOW) {
            $this->logger->info('Self-registration throttled', [
                'refused' => $refused,
                'accepted' => $accepted,
            ]);

            throw new TooManyAttemptsException(
                self::WINDOW_MINUTES * 60,
                'Too many registration attempts from this address. Please try again later.',
            );
        }
    }
}
