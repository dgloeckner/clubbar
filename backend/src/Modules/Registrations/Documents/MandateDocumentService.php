<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Logging\Logger;

/**
 * The club's Anmeldung, filled for one applicant (#780, UC-P01, UC-A17).
 *
 * ## Nothing is stored, on either path
 *
 * The template is fetched, filled and handed over inside one request. No copy
 * of the template is kept (ADR-0052 decision 5a: a pinned file would survive an
 * upgrade and vanish on restore, since the backup dumper walks tables only) and
 * no copy of the *filled* document is kept either — ADR-0037 is explicit that
 * the signed paper is the Beleg and this system holds no copies of it.
 *
 * ## The member's document is best-effort, and that is deliberate
 *
 * A club webhost outage must not cost a registration. {@see forMember()}
 * therefore returns null rather than throwing when the document cannot be
 * produced: the submission stands, and the admin-print variant — which the
 * Kassenwart runs later, from data that is already stored — is the path that
 * always works.
 *
 * What it does **not** do is substitute a different document. Falling back to a
 * neutral template would hand the applicant a mandate they did not read, and
 * would silently drop pages 2+ — which for the reference installation *are* the
 * Datenschutzhinweise they were pointed at. A missing document is recoverable;
 * a substituted one is a quiet lie.
 */
class MandateDocumentService
{
    public function __construct(
        private TemplateFetcher $fetcher,
        private MandateDocumentFiller $filler,
        private SepaConfigRepository $sepaConfig,
        private Logger $logger,
    ) {}

    /**
     * The member's copy, with the full IBAN — or null if it could not be made.
     *
     * @param array<string, mixed> $registration the row as it was just written
     * @param string $plaintextIban the normalized IBAN, alive only for this request
     */
    public function forMember(array $registration, string $plaintextIban): ?string
    {
        try {
            return $this->render($registration, MandateDocumentVariant::MEMBER, $plaintextIban);
        } catch (\Throwable $e) {
            // Swallowed on purpose, and logged without an identity. The
            // registration itself succeeded; refusing it now because a club's
            // webhost is down would throw away data the applicant already
            // typed, to no one's benefit.
            $this->logger->warning('Could not render the member document', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The Kassenwart's copy: IBAN line blank for handwriting, `****last4` as
     * the hint they check it against.
     *
     * Unlike the member's, this one **throws**. The admin asked for it
     * explicitly and is looking at the answer, so a refusal they can act on —
     * "rebuild the template with --pdf-forms" — beats a blank page.
     *
     * @param array<string, mixed> $registration
     * @throws UnusableTemplateException
     */
    public function forAdminPrint(array $registration): string
    {
        return $this->render($registration, MandateDocumentVariant::ADMIN_PRINT, null);
    }

    /**
     * @param array<string, mixed> $registration
     * @throws UnusableTemplateException
     */
    private function render(array $registration, MandateDocumentVariant $variant, ?string $iban): string
    {
        $url = (string) ($registration['privacy_notice_url'] ?? '');
        if ($url === '') {
            // The document the applicant was actually pointed at, recorded on
            // their row — not whatever the club has configured *today*. A club
            // that republished its Anmeldung last week must not silently change
            // the terms of a submission made before that.
            $url = $this->configuredUrl();
        }

        if ($url === '') {
            throw new UnusableTemplateException(TemplateProblem::NOT_A_PDF, [], 'No club document URL is configured.');
        }

        $template = $this->fetcher->fetch($url);
        if ($template === null) {
            throw new \App\Shared\Exceptions\BusinessRuleException(
                \App\Shared\Exceptions\BusinessRuleReason::DOCUMENT_TEMPLATE_UNREACHABLE,
                'The club document could not be fetched.',
                ['url' => $url],
            );
        }

        // An instance that configured its URL before the save-time check existed
        // (#812) is the one place a published template carrying somebody's data
        // still reaches a member — and it reaches them through the *link*, not
        // through this fill, which drops annotations and is unaffected. So this
        // is a log line and not a refusal: the registration must still produce
        // its document, and the club needs to be told that the file it points
        // every applicant at is showing them a stranger's details.
        //
        // The template is already in memory here, so this costs no fetch. The
        // field *names* are logged; their values never are.
        $prefilled = PdfAcroFormFields::prefilledFields($template);
        if ($prefilled !== []) {
            $this->logger->warning(
                'The club document is published with values in its form fields; '
                . 'every applicant is linked to it before entering anything',
                ['url' => $url, 'fields' => $prefilled],
            );
        }

        return $this->filler->fill($template, $this->values($registration, $variant, $iban));
    }

    /**
     * What goes in which field.
     *
     * A superset: a template carrying only some of these is valid, and the
     * filler drops the rest. Ort/Datum, the signatures and the Kenntnisnahme
     * boxes are absent because they are done by hand at signature — and a valid
     * template has no fields for them anyway, so there would be nowhere to draw.
     *
     * @param array<string, mixed> $registration
     * @return array<string, string>
     */
    private function values(array $registration, MandateDocumentVariant $variant, ?string $iban): array
    {
        $last4 = (string) ($registration['iban_last4'] ?? '');
        $sepa = $this->sepaConfig->getConfig();

        return [
            // Filled only where the template carries them. A club's own
            // document prints its creditor block statically — its identity
            // belongs in its own document — so these are usually dropped.
            'glaeubiger_name' => (string) ($sepa['creditor_name'] ?? ''),
            'glaeubiger_id' => (string) ($sepa['creditor_id'] ?? ''),

            'mandatsreferenz' => (string) ($registration['mandate_reference'] ?? ''),
            'vorname' => (string) ($registration['first_name'] ?? ''),
            'nachname' => (string) ($registration['last_name'] ?? ''),
            'geburtsdatum' => $this->germanDate((string) ($registration['date_of_birth'] ?? '')),
            'email' => (string) ($registration['email'] ?? ''),
            'kontoinhaber' => (string) ($registration['account_holder_name'] ?? ''),

            // The one field the variants disagree about.
            'iban' => $variant === MandateDocumentVariant::MEMBER ? $this->grouped((string) $iban) : '',
            'iban_last4' => $variant === MandateDocumentVariant::MEMBER
                ? '****' . $last4
                : 'endet auf ****' . $last4,
        ];
    }

    /**
     * `DE89 3704 0044 0532 0130 00` — the way an IBAN is printed on paper, and
     * the way somebody reading it back to their bank will read it.
     */
    private function grouped(string $iban): string
    {
        $compact = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

        return trim(chunk_split($compact, 4, ' '));
    }

    /** `1998-04-02` on the wire, `02.04.1998` on a German form. */
    private function germanDate(string $iso): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $iso);

        return $date === false ? $iso : $date->format('d.m.Y');
    }

    private function configuredUrl(): string
    {
        return (string) ($this->sepaConfig->getConfig()['mandate_template_url'] ?? '');
    }
}
