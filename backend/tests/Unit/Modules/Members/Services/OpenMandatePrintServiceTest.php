<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\Services\OpenMandatePrintService;
use App\Modules\Registrations\Documents\MandateDocumentFiller;
use App\Modules\Registrations\Documents\MandateDocumentService;
use App\Modules\Registrations\Documents\TemplateFetcher;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\PdfText;

/**
 * Printing open mandates in bulk (UC-A18).
 *
 * The document side is real — the filler, the service above it and the
 * three-page fixture — so "the full IBAN is on the page" and "it is not" are
 * read off an actual PDF. The members table and the key store are mocks: what
 * this class owes them is a precise sequence of reads, and whether those reads
 * return the right rows is the Feature suite's question.
 */
final class OpenMandatePrintServiceTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../../Fixtures/documents/club-anmeldung.pdf';
    private const ADMIN = '44444444-4444-4444-4444-444444444444';
    private const ACTIVE_KEY = 'key-active';
    private const IBAN = 'DE89370400440532013000';
    private const OTHER_IBAN = 'DE02120300000000202051';

    private MembersRepository&MockObject $members;
    private AuditService&MockObject $audit;
    private InMemoryTemplate $fetcher;
    private OpenMandatePrintService $service;

    /** @var list<array<int, mixed>> */
    private array $audited = [];

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $logger = new Logger(sys_get_temp_dir() . '/open-mandate-print-tests', 'CRITICAL');

        $sepaConfig = $this->createMock(SepaConfigRepository::class);
        $sepaConfig->method('getConfig')->willReturn([
            'creditor_name' => 'Ruderclub Musterstadt e.V.',
            'creditor_id' => 'DE98ZZZ09999999999',
            'mandate_template_url' => 'https://club.example/Anmeldung.pdf',
        ]);

        $this->fetcher = new InMemoryTemplate((string) file_get_contents(self::FIXTURE));

        $keys = $this->createMock(EncryptionKeysRepository::class);
        $keys->method('findActive')->willReturn(['id' => self::ACTIVE_KEY]);

        $this->members = $this->createMock(MembersRepository::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->audit->method('log')->willReturnCallback(function (...$args): void {
            $this->audited[] = $args;
        });

        $this->service = new OpenMandatePrintService(
            $this->members,
            new MandateDocumentService($this->fetcher, new MandateDocumentFiller(), $sepaConfig, $logger),
            $keys,
            $this->audit,
            $db,
            $logger,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function member(string $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'first_name' => 'Lena',
            'last_name' => 'Brandt',
            'email' => 'lena@example.org',
            'date_of_birth' => '1998-04-02',
            'account_holder_name' => null,
            'mandate_reference' => 'CB-000041',
            'iban_last4' => '3000',
            'encryption_key_id' => self::ACTIVE_KEY,
        ];
    }

    /** An opener that "decrypts" by stripping a prefix, and remembers what it was handed. */
    private function opener(array &$opened): \Closure
    {
        return static function (string $ciphertext) use (&$opened): string {
            $opened[] = $ciphertext;

            return substr($ciphertext, strlen('sealed:'));
        };
    }

    private function text(string $pdf): string
    {
        // One implementation for every test that reads a PDF — and the reason
        // it is not a regex here is written down there.
        return PdfText::of($pdf);
    }

    private function pageCount(string $pdf): int
    {
        return (new MandateDocumentFiller())->pageCount($pdf);
    }

    // ── what is printed ──────────────────────────────────────────────────

    public function test_the_document_is_the_whole_anmeldung_once_per_open_mandate(): void
    {
        $this->members->method('findOpenMandates')->willReturn([
            $this->member('m-1'),
            $this->member('m-2', ['first_name' => 'Jonas']),
        ]);

        $result = $this->service->render(null, null, self::ADMIN);

        self::assertSame(6, $this->pageCount($result->pdf), 'Three template pages, two members.');
        self::assertSame(2, $result->sheetCount);
    }

    /** Without a key there is nothing to open — and nothing is even read from the sealed column. */
    public function test_the_blank_print_never_touches_the_sealed_iban(): void
    {
        $this->members->method('findOpenMandates')->willReturn([$this->member('m-1')]);
        $this->members->expects(self::never())->method('findSealedIban');

        $result = $this->service->render(null, null, self::ADMIN);

        self::assertStringNotContainsString('DE89', $this->text($result->pdf));
        self::assertStringContainsString('endet auf ****3000', $this->text($result->pdf));
        self::assertSame(0, $result->withoutIbanCount, 'A blank line is what a blank print asked for.');
    }

    public function test_the_keyed_print_carries_the_full_iban(): void
    {
        $this->members->method('findOpenMandates')->willReturn([$this->member('m-1')]);
        $this->members->method('findSealedIban')->willReturn([
            'iban_ciphertext' => 'sealed:' . self::IBAN,
            'encryption_key_id' => self::ACTIVE_KEY,
        ]);

        $opened = [];
        $result = $this->service->render(null, $this->opener($opened), self::ADMIN);

        self::assertStringContainsString('DE89 3704 0044 0532 0130 00', $this->text($result->pdf));
        self::assertSame(['sealed:' . self::IBAN], $opened);
        self::assertSame(0, $result->withoutIbanCount);
    }

    /**
     * Mid-rotation, some mandates are still sealed under the retiring key. The
     * key in hand cannot open them, the opener is not even asked to try, and
     * those members get the blank sheet — the rest of the batch is unaffected.
     */
    public function test_a_mandate_under_another_key_is_printed_blank_rather_than_failing_the_batch(): void
    {
        $this->members->method('findOpenMandates')->willReturn([
            $this->member('today', ['mandate_reference' => 'CB-000041']),
            $this->member('last-year', ['iban_last4' => '2051', 'mandate_reference' => 'CB-000042']),
        ]);
        $this->members->method('findSealedIban')->willReturnCallback(static fn(string $id): array => $id === 'today'
            ? ['iban_ciphertext' => 'sealed:' . self::IBAN, 'encryption_key_id' => self::ACTIVE_KEY]
            : ['iban_ciphertext' => 'sealed:' . self::OTHER_IBAN, 'encryption_key_id' => 'key-retiring']);

        $opened = [];
        $result = $this->service->render(null, $this->opener($opened), self::ADMIN);

        $text = $this->text($result->pdf);
        self::assertStringContainsString('DE89 3704 0044 0532 0130 00', $text);
        self::assertStringNotContainsString('DE02', $text, 'The other key\'s IBAN must not be opened or printed.');
        self::assertStringContainsString('endet auf ****2051', $text);
        self::assertSame(['sealed:' . self::IBAN], $opened, 'The opener is never handed a box it cannot open.');
        self::assertSame(2, $result->sheetCount);
        self::assertSame(1, $result->withoutIbanCount);
    }

    /** A damaged box under the right key costs that member's IBAN line, not the print. */
    public function test_a_box_that_will_not_open_is_printed_blank(): void
    {
        $this->members->method('findOpenMandates')->willReturn([$this->member('m-1')]);
        $this->members->method('findSealedIban')->willReturn([
            'iban_ciphertext' => 'damaged',
            'encryption_key_id' => self::ACTIVE_KEY,
        ]);

        $result = $this->service->render(null, static function (): string {
            throw new \RuntimeException('sealed box could not be opened');
        }, self::ADMIN);

        self::assertStringContainsString('endet auf ****3000', $this->text($result->pdf));
        self::assertSame(1, $result->withoutIbanCount);
    }

    public function test_the_print_can_be_narrowed_to_named_members(): void
    {
        $this->members->expects(self::once())->method('findOpenMandates')
            ->with(['m-2'])
            ->willReturn([$this->member('m-2')]);

        $this->service->render(['m-2'], null, self::ADMIN);
    }

    // ── what is refused ──────────────────────────────────────────────────

    /**
     * Named, rather than answered with an empty PDF — which a viewer opens as
     * an error, while "there is nothing to print" is all the admin needs.
     */
    public function test_nobody_waiting_for_a_signature_is_a_named_refusal(): void
    {
        $this->members->method('findOpenMandates')->willReturn([]);

        try {
            $this->service->render(null, null, self::ADMIN);
            self::fail('An empty print must be refused.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::NO_OPEN_MANDATES, $e->getReason());
        }

        self::assertSame(0, $this->fetcher->calls, 'Nothing to print means nothing to fetch.');
        self::assertSame([], $this->audited);
    }

    // ── what is recorded ─────────────────────────────────────────────────

    /**
     * A blank sheet is the Anmeldung printed with an IBAN hint — what
     * `registration_printed` has always recorded — against the *member*, with
     * a variant that tells it apart from a pending registration's print.
     */
    public function test_a_blank_sheet_is_audited_as_a_print_against_the_member(): void
    {
        $this->members->method('findOpenMandates')->willReturn([$this->member('m-1')]);

        $this->service->render(null, null, self::ADMIN);

        self::assertCount(1, $this->audited);
        [$action, $entityType, $entityId, , $newValues, $adminUserId] = $this->audited[0] + [3 => null];
        self::assertSame(AuditAction::REGISTRATION_PRINTED, $action);
        self::assertSame(EntityType::MEMBER, $entityType);
        self::assertSame('m-1', $entityId);
        self::assertSame('open_mandate', $newValues['variant']);
        self::assertSame('****3000', $newValues['iban']);
        self::assertSame(self::ADMIN, $adminUserId);
    }

    /** Precisely ADR-0036's "exceptional full-IBAN display" — one entry per member whose number was printed. */
    public function test_a_full_iban_sheet_is_audited_as_a_full_iban_view(): void
    {
        $this->members->method('findOpenMandates')->willReturn([
            $this->member('today'),
            $this->member('last-year', ['iban_last4' => '2051']),
        ]);
        $this->members->method('findSealedIban')->willReturnCallback(static fn(string $id): array => $id === 'today'
            ? ['iban_ciphertext' => 'sealed:' . self::IBAN, 'encryption_key_id' => self::ACTIVE_KEY]
            : ['iban_ciphertext' => 'sealed:' . self::OTHER_IBAN, 'encryption_key_id' => 'key-retiring']);

        $opened = [];
        $this->service->render(null, $this->opener($opened), self::ADMIN);

        $byMember = [];
        foreach ($this->audited as $entry) {
            $byMember[$entry[2]] = $entry;
        }

        self::assertSame(AuditAction::IBAN_FULL_VIEW, $byMember['today'][0]);
        self::assertSame(EntityType::MEMBER, $byMember['today'][1]);
        // What left the building, not what was asked for: the sheet that was
        // printed blank after all is recorded as a blank print.
        self::assertSame(AuditAction::REGISTRATION_PRINTED, $byMember['last-year'][0]);
    }

    /** ADR-0005: the log records *that* a number was printed, never the number. */
    public function test_no_entry_ever_carries_a_full_iban(): void
    {
        $this->members->method('findOpenMandates')->willReturn([$this->member('m-1')]);
        $this->members->method('findSealedIban')->willReturn([
            'iban_ciphertext' => 'sealed:' . self::IBAN,
            'encryption_key_id' => self::ACTIVE_KEY,
        ]);

        $opened = [];
        $this->service->render(null, $this->opener($opened), self::ADMIN);

        $payload = json_encode($this->audited);
        self::assertStringContainsString('****3000', $payload);
        self::assertStringNotContainsString(self::IBAN, $payload);
        self::assertStringNotContainsString('sealed:', $payload, 'No ciphertext reaches the log either.');
    }

    /**
     * An entry claiming a full IBAN left the building when the render then
     * failed is worse than no entry: it records something that did not happen.
     */
    public function test_a_render_that_fails_writes_no_audit_entry(): void
    {
        $this->members->method('findOpenMandates')->willReturn([$this->member('m-1')]);
        $this->fetcher->body = null;

        try {
            $this->service->render(null, null, self::ADMIN);
            self::fail('An unreachable document must be refused.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::DOCUMENT_TEMPLATE_UNREACHABLE, $e->getReason());
        }

        self::assertSame([], $this->audited);
    }
}

/** The club's document, answered from memory. */
final class InMemoryTemplate implements TemplateFetcher
{
    public int $calls = 0;

    public function __construct(public ?string $body) {}

    public function fetch(string $url, int $timeoutSeconds = 10): ?string
    {
        $this->calls++;

        return $this->body;
    }
}
