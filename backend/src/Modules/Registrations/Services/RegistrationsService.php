<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Services;

use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\Registrations\Documents\MandateDocumentService;
use App\Modules\Registrations\Domain\PosterSecret;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\Registrations\DTOs\RegistrationContextDto;
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
use App\Shared\Branding\PublicBranding;
use App\Shared\Branding\PublicBrandingProvider;
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
     * Two meters, because one is not enough — and two budgets, because they are
     * defending against opposite things.
     *
     * **Refusals** are metered like a password guess: whoever is probing for a
     * valid poster secret spends the budget they would spend guessing a login.
     * Tight, and safe to keep tight, because a legitimate visitor presents the
     * secret their poster gave them and never trips it.
     *
     * **Accepted submissions** are metered far more loosely, and the reason is
     * the whole point of the feature: this is a QR code *in a clubhouse*.
     * Everybody who scans it is on the club's wifi, behind one NAT address. A
     * signup evening is six people in ten minutes from a single IP — which a
     * tight budget would read as an attack and refuse, at exactly the moment
     * the club is using the thing as designed. The budget here is sized to stop
     * a script writing thousands of rows, not to ration a table of members.
     */
    public const MAX_REFUSED_PER_WINDOW = 10;
    public const REFUSED_WINDOW_MINUTES = 15;

    public const MAX_ACCEPTED_PER_WINDOW = 60;
    public const ACCEPTED_WINDOW_MINUTES = 60;

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
        // Optional and last: the document is best-effort by design (decision
        // 5), and an installation wired without it still registers members.
        private ?MandateDocumentService $documents = null,
        // Optional for the same reason: the club's name and mark decorate the
        // onboarding page, and a page without them still registers members.
        private ?PublicBrandingProvider $branding = null,
    ) {}

    /**
     * @param array<string, mixed> $data already validated by the controller
     * @throws NotFoundException the uniform refusal: no secret, wrong secret,
     *         or a club that has never generated one — indistinguishable on
     *         purpose, because telling them apart confirms a secret exists
     * @throws BusinessRuleException the club has switched registration off, or
     *         has no document URL to point the applicant at
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

        // The second fail-closed condition, checked here and not only where an
        // admin flips the switch (UC-A69). Relying on the write path alone
        // would leave the gate open to a database restore, a hand-edited row,
        // or the URL being cleared *after* registration went live — and
        // accepting then collects a name, a birth date and an IBAN from
        // somebody who was never shown the notice. That is the whole reason
        // this condition exists (ADR-0052 decision 6).
        $documentUrl = $this->documentUrl();
        if ($documentUrl === '') {
            $this->attempts->record($ip, RegistrationAttemptsRepository::REFUSED);
            $this->logger->warning('Self-registration refused: no club document URL is configured');

            throw new BusinessRuleException(
                BusinessRuleReason::DOCUMENT_URL_MISSING,
                'Self-registration is switched on but no club document URL is configured.',
            );
        }

        // From here the caller is legitimate, so the accepted meter is what
        // governs them — recorded even for the honeypot, so a bot cannot use
        // the trap as an unmetered channel.
        $this->attempts->record($ip, RegistrationAttemptsRepository::ACCEPTED);

        if (($data[self::HONEYPOT_FIELD] ?? '') !== '') {
            $this->logger->info('Self-registration honeypot tripped');

            // Shaped exactly like a real receipt, down to the document field.
            // A bot that could tell the trap apart by a missing key would have
            // learned the one thing the trap exists to hide — and `document`
            // being null is an ordinary outcome for a real submission too.
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
            'privacy_notice_url' => $documentUrl,
            'privacy_notice_shown_at' => $submittedAt,
            'submitted_at' => $submittedAt,
            'expires_at' => $expiresAt,
        ]);

        // The last use of the plaintext, and the only chance there will ever be
        // to print it. After this line it exists nowhere: sealed in the row
        // above, under a key this server does not hold (ADR-0036). Rendering
        // here rather than behind a download endpoint is not a convenience —
        // there is no later request that *could* render it.
        //
        // Best-effort: a club webhost outage must not cost a registration that
        // has already been written, so a document that cannot be produced is
        // absent rather than fatal. The admin-print variant needs no plaintext
        // and still works.
        $document = $this->documents?->forMember([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'date_of_birth' => $data['date_of_birth'],
            'account_holder_name' => $data['account_holder_name'] ?? null,
            'mandate_reference' => $mandateReference,
            'iban_last4' => IbanSealedBox::lastFour($iban),
            'privacy_notice_url' => $documentUrl,
        ], $iban);

        // No identity, here or anywhere else on this route.
        $this->logger->info('Self-registration accepted', ['document' => $document !== null]);

        return new RegistrationReceiptDto(
            id: $id,
            mandateReference: $mandateReference,
            documentBase64: $document === null ? null : base64_encode($document),
        );
    }

    /**
     * What the onboarding page needs before it renders a single field (#781).
     *
     * Gated by the same secret and refused the same uniform way, because an
     * endpoint that confirmed a valid secret exists would be a free oracle for
     * whoever is guessing one — cheaper than the submit path, since it costs no
     * form to try.
     *
     * ## Metered on refusals only, deliberately
     *
     * A refused lookup is a guess and spends the guessing budget. A *successful*
     * one does not touch the submission budget: the page loads this every time
     * it opens, and a member who reads the club's document before filling
     * anything in opens it twice. Charging that against the accepted-submission
     * meter would refuse the sixth honest visitor at a signup evening for the
     * crime of being careful — the same miscalibration the accepted meter was
     * already widened to avoid.
     *
     * ## Unavailability is answered, not thrown
     *
     * A club that is switched off is not an error on this path: it is the
     * answer. The page renders the club's own paused screen from it, which is
     * the whole reason the endpoint exists.
     *
     * @throws NotFoundException the uniform refusal for a wrong or missing secret
     * @throws TooManyAttemptsException too many guesses from this address
     */
    public function context(string $presentedSecret, string $ip): RegistrationContextDto
    {
        $this->assertWithinMeters($ip);

        $config = $this->config->get();

        if (!PosterSecret::matches($presentedSecret, $config->secretHash)) {
            $this->attempts->record($ip, RegistrationAttemptsRepository::REFUSED);
            $this->logger->info('Self-registration context refused: secret did not match');

            throw new NotFoundException('Not found');
        }

        $documentUrl = $this->documentUrl();
        $languages = array_column(SupportedLanguage::cases(), 'value');
        // Past the gate, so the caller demonstrably holds the club's poster.
        // Every screen the page can now render is one the club puts its name
        // on — the paused one included, which is the club speaking.
        $branding = $this->branding?->get() ?? new PublicBranding();

        if (!$config->enabled) {
            return new RegistrationContextDto(
                available: false,
                reason: BusinessRuleReason::REGISTRATION_DISABLED->value,
                message: $config->disabledReason,
                documentUrl: $documentUrl === '' ? null : $documentUrl,
                languages: $languages,
                retentionDays: $config->retentionDays,
                branding: $branding,
            );
        }

        // The same fail-closed condition the submit path enforces, answered
        // *before* a field is rendered. A form that collects a name, a birth
        // date and an IBAN and only then discovers nobody could have been shown
        // a notice has already wasted the applicant's time on data it must
        // refuse — and has already had it typed into a phone.
        if ($documentUrl === '') {
            $this->logger->warning('Self-registration context: no club document URL is configured');

            return new RegistrationContextDto(
                available: false,
                reason: BusinessRuleReason::DOCUMENT_URL_MISSING->value,
                message: null,
                documentUrl: null,
                languages: $languages,
                retentionDays: $config->retentionDays,
                branding: $branding,
            );
        }

        return new RegistrationContextDto(
            available: true,
            reason: null,
            message: null,
            documentUrl: $documentUrl,
            languages: $languages,
            retentionDays: $config->retentionDays,
            branding: $branding,
        );
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
        $refusedSince = date('Y-m-d H:i:s', strtotime('-' . self::REFUSED_WINDOW_MINUTES . ' minutes'));
        $acceptedSince = date('Y-m-d H:i:s', strtotime('-' . self::ACCEPTED_WINDOW_MINUTES . ' minutes'));

        $refused = $this->attempts->countRecent($ip, RegistrationAttemptsRepository::REFUSED, $refusedSince);
        $accepted = $this->attempts->countRecent($ip, RegistrationAttemptsRepository::ACCEPTED, $acceptedSince);

        if ($refused >= self::MAX_REFUSED_PER_WINDOW || $accepted >= self::MAX_ACCEPTED_PER_WINDOW) {
            $this->logger->info('Self-registration throttled', [
                'refused' => $refused,
                'accepted' => $accepted,
            ]);

            throw new TooManyAttemptsException(
                self::REFUSED_WINDOW_MINUTES * 60,
                'Too many registration attempts from this address. Please try again later.',
            );
        }
    }
}
