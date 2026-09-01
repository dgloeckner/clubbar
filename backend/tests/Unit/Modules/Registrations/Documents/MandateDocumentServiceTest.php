<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Documents;

use App\Modules\Registrations\Documents\MandateDocumentFiller;
use App\Modules\Registrations\Documents\PdfAcroFormFields;
use App\Modules\Registrations\Documents\MandateDocumentService;
use App\Modules\Registrations\Documents\TemplateFetcher;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Turning one applicant's row into the club's filled Anmeldung (#780).
 *
 * The filler is real and so is the fixture; only the fetch is faked, because no
 * test opens a socket. What is asserted here is the part above the filler: which
 * value goes in which field, how the two variants differ, and what happens when
 * the club's webhost is down.
 */
final class MandateDocumentServiceTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../../Fixtures/documents/club-anmeldung.pdf';
    private const IBAN = 'DE89370400440532013000';

    private RecordingFetcher $fetcher;
    private MandateDocumentService $service;

    protected function setUp(): void
    {
        $this->fetcher = new RecordingFetcher((string) file_get_contents(self::FIXTURE));

        $sepaConfig = $this->createMock(SepaConfigRepository::class);
        $sepaConfig->method('getConfig')->willReturn([
            'creditor_name' => 'Ruderclub Musterstadt e.V.',
            'creditor_id' => 'DE98ZZZ09999999999',
            'mandate_template_url' => 'https://club.example/configured-today.pdf',
        ]);

        $this->service = new MandateDocumentService(
            $this->fetcher,
            new MandateDocumentFiller(),
            $sepaConfig,
            new Logger(sys_get_temp_dir() . '/mandate-document-tests', 'CRITICAL'),
        );
    }

    /** @return array<string, mixed> */
    private function registration(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Lena',
            'last_name' => 'Brandt',
            'email' => 'lena@example.org',
            'date_of_birth' => '1998-04-02',
            'account_holder_name' => null,
            'mandate_reference' => 'c0ffee1234beef9d41d8cd98f00b204a',
            'iban_last4' => '3000',
            'privacy_notice_url' => 'https://club.example/Anmeldung_shown_to_them.pdf',
        ];
    }

    /**
     * The text a filled document actually carries.
     *
     * FPDF compresses its own content stream, so the values it drew are not
     * literal bytes in the output — an assertion against the raw PDF passes or
     * fails for reasons unrelated to what is on the page. Inflating first is
     * what makes "does this appear on a page" answerable.
     */
    private function text(string $pdf): string
    {
        $text = $pdf;

        if (preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $streams)) {
            foreach ($streams[1] as $stream) {
                $inflated = @gzuncompress($stream);
                if ($inflated !== false) {
                    $text .= $inflated;
                }
            }
        }

        return $text;
    }

    /**
     * Every content stream, inflated where it is compressed.
     *
     * @return list<string>
     */
    private function contentStreams(string $pdf): array
    {
        $streams = [];

        if (preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $matches)) {
            foreach ($matches[1] as $stream) {
                $inflated = @gzuncompress($stream);
                $streams[] = $inflated === false ? $stream : $inflated;
            }
        }

        return $streams;
    }

    public function test_the_member_document_carries_the_full_iban_grouped_for_reading(): void
    {
        $pdf = $this->service->forMember($this->registration(), self::IBAN);

        self::assertNotNull($pdf);
        // Grouped in fours, the way it is printed on paper and the way somebody
        // reads it back to their bank.
        self::assertStringContainsString('DE89 3704 0044 0532 0130 00', $this->text($pdf));
    }

    public function test_the_admin_document_leaves_the_iban_blank_and_prints_the_hint(): void
    {
        $pdf = $this->service->forAdminPrint($this->registration());

        self::assertStringNotContainsString('DE89', $this->text($pdf));
        self::assertStringContainsString('endet auf ****3000', $this->text($pdf));
    }

    /**
     * The distinction ADR-0036 rests on: the admin path needs no plaintext at
     * all, so there is nothing to decrypt and no exception to make. It is
     * therefore also the path that still works weeks later, when the member's
     * tab is long gone.
     */
    public function test_the_admin_document_needs_no_plaintext_iban(): void
    {
        $row = $this->registration();
        self::assertArrayNotHasKey('iban', $row);

        self::assertStringStartsWith('%PDF-', $this->service->forAdminPrint($row));
    }

    public function test_the_member_document_carries_the_personal_fields(): void
    {
        $pdf = $this->text((string) $this->service->forMember(
            $this->registration(['account_holder_name' => 'Petra Brandt']),
            self::IBAN,
        ));

        self::assertStringContainsString('Lena', $pdf);
        self::assertStringContainsString('Brandt', $pdf);
        self::assertStringContainsString('lena@example.org', $pdf);
        self::assertStringContainsString('c0ffee1234beef9d41d8cd98f00b204a', $pdf);
        self::assertStringContainsString('Petra Brandt', $pdf);
    }

    /** `1998-04-02` on the wire; a German form says `02.04.1998`. */
    public function test_the_birth_date_is_printed_the_way_a_german_form_reads_it(): void
    {
        $pdf = $this->text((string) $this->service->forMember($this->registration(), self::IBAN));

        self::assertStringContainsString('02.04.1998', $pdf);
        self::assertStringNotContainsString('1998-04-02', $pdf);
    }

    /**
     * The creditor block: filled where the template carries fields for it, which
     * is the shipped neutral case. A club's own document prints its identity
     * statically, and those values are simply dropped.
     */
    /**
     * The signature line stays empty, in **both** variants (#784).
     *
     * The fixture makes `ort_datum` and `unterschrift` real AcroForm fields
     * precisely so this is provable rather than incidental: a club whose
     * designer made the signature line fillable is a template this pipeline has
     * to handle *without* filling it. A machine-printed place and date on a SEPA
     * mandate is a claim nobody made — the member writes both at signature, and
     * that handwriting is what the Kassenwart's attestation later refers to.
     *
     * `MandateDocumentFillerTest` proves the same thing about the mechanism,
     * with a control run showing the fields really are fillable. This is the
     * claim one level up: that **neither variant's value map** carries a key for
     * them, which is the half a caller could break on its own.
     */
    public function test_neither_variant_fills_the_place_or_the_signature(): void
    {
        $fields = PdfAcroFormFields::scan(
            (string) file_get_contents(__DIR__ . '/../../../../Fixtures/documents/club-anmeldung.pdf'),
        );
        // Vacuous otherwise: a rebuild that dropped the fields would leave this
        // asserting that nothing was written into nothing.
        self::assertArrayHasKey('ort_datum', $fields);
        self::assertArrayHasKey('unterschrift', $fields);

        $documents = [
            'member' => (string) $this->service->forMember($this->registration(), self::IBAN),
            'admin' => $this->service->forAdminPrint($this->registration()),
        ];

        foreach ($documents as $variant => $pdf) {
            foreach (['ort_datum', 'unterschrift'] as $name) {
                self::assertSame(
                    [],
                    $this->runsInside($pdf, $fields[$name]),
                    "{$variant} variant wrote into {$name}",
                );
            }
        }
    }

    /**
     * The text drawn inside one field's rectangle, if any.
     *
     * FPDF writes each value as `BT x y Td (…) Tj ET` in PDF user space measured
     * from the page's bottom-left — the same space `/Rect` uses, which is what
     * makes the comparison exact rather than approximate. Checking *where*
     * rather than *what* is the point: the failure to catch is somebody adding
     * today's date as a convenience, and the string that would print is not
     * knowable in advance.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $rect
     * @return list<string>
     */
    private function runsInside(string $pdf, array $rect): array
    {
        [$x1, $y1, $x2, $y2] = $rect;
        $found = [];

        // Inflated first: FPDF's own stream is uncompressed but the imported
        // pages are Flate, and a scan over the raw bytes silently finds nothing
        // at all — which reads as "the field was left empty" for every field,
        // including the ones that were filled.
        foreach ($this->contentStreams($pdf) as $chunk) {
            if (preg_match_all('~BT\s+([0-9.-]+)\s+([0-9.-]+)\s+Td\s*\((.*?)\)\s*Tj~s', $chunk, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $x = (float) $match[1];
                    $y = (float) $match[2];
                    if ($x >= $x1 - 1 && $x <= $x2 + 1 && $y >= $y1 - 1 && $y <= $y2 + 1) {
                        $found[] = $match[3];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Every page of the club's document survives, in both variants (#784).
     *
     * The Datenschutzhinweise and the Nutzungsordnung ride behind the form page
     * and are part of what the member signs (ADR-0052 decision 5). Losing them
     * would be invisible on screen — page one looks perfect — and would hand the
     * member a mandate without the notice they were pointed at.
     */
    public function test_both_variants_keep_every_page_of_the_template(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../../Fixtures/documents/club-anmeldung.pdf');
        $expected = $this->pageCount($template);
        self::assertGreaterThan(1, $expected, 'a single-page fixture would prove nothing');

        self::assertSame(
            $expected,
            $this->pageCount((string) $this->service->forMember($this->registration(), self::IBAN)),
            'member variant lost a page',
        );
        self::assertSame(
            $expected,
            $this->pageCount($this->service->forAdminPrint($this->registration())),
            'admin variant lost a page',
        );
    }

    /** `/Type /Page` occurrences, minus the one `/Type /Pages` node they hang from. */
    private function pageCount(string $pdf): int
    {
        return preg_match_all('~/Type\s*/Page[^s]~', $pdf);
    }

    public function test_the_creditor_block_is_filled_when_the_template_has_fields_for_it(): void
    {
        $pdf = $this->text((string) $this->service->forMember($this->registration(), self::IBAN));

        self::assertStringContainsString('Ruderclub Musterstadt e.V.', $pdf);
        self::assertStringContainsString('DE98ZZZ09999999999', $pdf);
    }

    /**
     * The document the applicant was *shown*, recorded on their row — not
     * whatever the club has configured today. A club that republished its
     * Anmeldung last week must not silently change the terms of a submission
     * made before that.
     */
    public function test_it_fetches_the_document_the_applicant_was_pointed_at(): void
    {
        $this->service->forMember($this->registration(), self::IBAN);

        self::assertSame('https://club.example/Anmeldung_shown_to_them.pdf', $this->fetcher->lastUrl);
    }

    public function test_it_falls_back_to_the_configured_url_for_a_row_that_recorded_none(): void
    {
        $this->service->forMember($this->registration(['privacy_notice_url' => '']), self::IBAN);

        self::assertSame('https://club.example/configured-today.pdf', $this->fetcher->lastUrl);
    }

    /**
     * A club webhost outage must not cost a registration. The submission has
     * already succeeded by the time this runs; refusing it now would throw away
     * data the applicant already typed, to nobody's benefit.
     */
    public function test_a_member_document_that_cannot_be_fetched_is_simply_absent(): void
    {
        $this->fetcher->body = null;

        self::assertNull($this->service->forMember($this->registration(), self::IBAN));
    }

    public function test_an_unusable_template_leaves_the_member_without_a_document_rather_than_failing(): void
    {
        $this->fetcher->body = '<html>404 Not Found</html>';

        self::assertNull($this->service->forMember($this->registration(), self::IBAN));
    }

    /**
     * Never a different document, though. Substituting a neutral template would
     * hand the applicant a mandate they never read, and would drop pages 2+ —
     * which for a real club document *are* the Datenschutzhinweise they were
     * pointed at. A missing document is recoverable; a substituted one is a
     * quiet lie.
     */
    public function test_it_never_substitutes_another_document(): void
    {
        $this->fetcher->body = null;

        self::assertNull($this->service->forMember($this->registration(), self::IBAN));
        self::assertSame(1, $this->fetcher->calls, 'A second fetch would mean a fallback source exists.');
    }

    /**
     * The admin asked explicitly and is looking at the answer, so they get a
     * refusal they can act on rather than a blank page.
     */
    public function test_the_admin_path_refuses_out_loud_when_the_document_cannot_be_fetched(): void
    {
        $this->fetcher->body = null;

        try {
            $this->service->forAdminPrint($this->registration());
            self::fail('The admin path must not fail silently.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::DOCUMENT_TEMPLATE_UNREACHABLE, $e->getReason());
        }
    }

    public function test_the_admin_path_names_an_unreadable_template(): void
    {
        $this->fetcher->body = '<html>404 Not Found</html>';

        try {
            $this->service->forAdminPrint($this->registration());
            self::fail('The admin path must not fail silently.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::DOCUMENT_TEMPLATE_NOT_A_PDF, $e->getReason());
        }
    }

    /**
     * Neither path writes anything anywhere. The template is not cached and the
     * filled document is not kept: ADR-0037 is explicit that the signed paper is
     * the Beleg and this system holds no copies of it.
     */
    public function test_neither_path_leaves_a_file_behind(): void
    {
        $before = $this->tempFiles();

        $this->service->forMember($this->registration(), self::IBAN);
        $this->service->forAdminPrint($this->registration());

        self::assertSame($before, $this->tempFiles());
    }

    /** @return list<string> */
    private function tempFiles(): array
    {
        $found = glob(sys_get_temp_dir() . '/clubbar-template-*') ?: [];
        sort($found);

        return $found;
    }
}

/** A fetcher that answers from memory and remembers what it was asked. */
final class RecordingFetcher implements TemplateFetcher
{
    public int $calls = 0;
    public ?string $lastUrl = null;

    public function __construct(
        public ?string $body,
    ) {}

    public function fetch(string $url, int $timeoutSeconds = 10): ?string
    {
        $this->calls++;
        $this->lastUrl = $url;

        return $this->body;
    }
}
