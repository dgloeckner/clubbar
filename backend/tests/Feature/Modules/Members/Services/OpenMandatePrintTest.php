<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members\Services;

use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\Services\OpenMandatePrintService;
use App\Modules\Registrations\Documents\MandateDocumentFiller;
use App\Modules\Registrations\Documents\MandateDocumentService;
use App\Modules\Registrations\Documents\TemplateFetcher;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use App\Shared\Utils\Uuid;
use Tests\Feature\DatabaseTestCase;
use Tests\Support\MandateReferences;

/**
 * Printing open mandates, against the real tables and a real sealed box
 * (UC-A18).
 *
 * The unit suite covers what is drawn and what is recorded with the members
 * table mocked. What it cannot show is the two things that only exist in
 * MariaDB:
 *
 * 1. **Who is in the document.** "Active, not anonymized, mandate open and
 *    unsigned" is a join and three predicates, and each one excludes somebody
 *    the print must not reach.
 * 2. **That the IBAN on the page is the one that was sealed.** The keyed print
 *    opens the stored ciphertext with the club's private key; this does it
 *    with the published development keypair and reads the number off the PDF.
 */
final class OpenMandatePrintTest extends DatabaseTestCase
{
    private const FIXTURE = __DIR__ . '/../../../../Fixtures/documents/club-anmeldung.pdf';

    private MembersRepository $members;
    private OpenMandatePrintService $service;

    /** @var list<string> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureActiveEncryptionKey();

        $keys = new EncryptionKeysRepository($this->db, $this->logger);

        $this->members = new MembersRepository(
            $this->db,
            $this->logger,
            new IbanSealedBox(str_repeat('0', 63) . '2', 'test'),
            $keys,
            MandateReferences::real($this->db, $this->logger),
        );

        $template = (string) file_get_contents(self::FIXTURE);

        $this->service = new OpenMandatePrintService(
            $this->members,
            new MandateDocumentService(
                new class ($template) implements TemplateFetcher {
                    public function __construct(private string $body) {}

                    public function fetch(string $url, int $timeoutSeconds = 10): ?string
                    {
                        return $this->body;
                    }
                },
                new MandateDocumentFiller(),
                // The real row: the print reads the club's configured URL from
                // it, and an install with none configured is refused before a
                // fetch — so the test makes sure one is there.
                $this->sepaConfigWithTemplateUrl(),
                $this->logger,
            ),
            $keys,
            new AuditService(new AuditLogRepository($this->db, $this->logger)),
            $this->db,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->memberIds as $id) {
            $this->db->prepare('DELETE FROM audit_log WHERE entity_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM mandates WHERE member_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
        }

        parent::tearDown();
    }

    /**
     * A repository over the real `sepa_config` row whose template URL is
     * always set, without writing to a singleton other suites share.
     */
    private function sepaConfigWithTemplateUrl(): SepaConfigRepository
    {
        $real = new SepaConfigRepository($this->db, $this->logger);

        return new class ($real) extends SepaConfigRepository {
            public function __construct(private SepaConfigRepository $real) {}

            public function getConfig(): ?array
            {
                return ['mandate_template_url' => 'https://club.example/Anmeldung.pdf'] + ($this->real->getConfig() ?? []);
            }
        };
    }

    /** A valid German IBAN no other row in this database holds. */
    private function uniqueIban(): string
    {
        $bban = '37040044' . str_pad((string) random_int(1, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        $check = 98 - (int) bcmod($bban . '131400', '97');

        return 'DE' . str_pad((string) $check, 2, '0', STR_PAD_LEFT) . $bban;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> the member row
     */
    private function member(array $overrides = []): array
    {
        $suffix = substr(Uuid::v4(), 0, 8);

        $member = $this->members->create($overrides + [
            'first_name' => 'Offen',
            'last_name' => 'Mandat' . $suffix,
            'email' => "open-{$suffix}@example.org",
            'date_of_birth' => '1998-04-02',
            'preferred_language' => 'de',
            'iban' => $this->uniqueIban(),
        ]);
        $this->memberIds[] = $member['id'];

        return $member;
    }

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

    private function grouped(string $iban): string
    {
        return trim(chunk_split($iban, 4, ' '));
    }

    // ── who is in the document ───────────────────────────────────────────

    public function test_only_members_whose_open_mandate_is_unsigned_are_listed(): void
    {
        $open = $this->member();
        $signed = $this->member(['mandate_signed_at' => '2026-01-15']);
        $noMandate = $this->member(['iban' => null]);
        $inactive = $this->member(['is_active' => false]);

        $listed = array_column($this->members->findOpenMandates($this->memberIds), 'id');

        $this->assertContains($open['id'], $listed);
        $this->assertNotContains($signed['id'], $listed, 'A signed mandate is done.');
        $this->assertNotContains($noMandate['id'], $listed, 'No mandate means no reference to print.');
        $this->assertNotContains($inactive['id'], $listed, 'A deactivated member is not being onboarded.');
    }

    public function test_an_anonymized_member_is_never_printed(): void
    {
        $erased = $this->member();
        $this->members->anonymize($erased['id']);

        $this->assertSame([], $this->members->findOpenMandates([$erased['id']]));
    }

    /** The row carries what the sheet needs — and not the ciphertext. */
    public function test_the_listing_never_carries_the_sealed_iban(): void
    {
        $open = $this->member(['account_holder_name' => 'Petra Brandt']);

        $row = $this->members->findOpenMandates([$open['id']])[0];

        $this->assertSame('Petra Brandt', $row['account_holder_name']);
        $this->assertSame($open['mandate_reference'], $row['mandate_reference']);
        $this->assertNotEmpty($row['encryption_key_id'], 'Which key sealed it travels with the row.');
        $this->assertArrayNotHasKey('iban_ciphertext', $row);
        $this->assertArrayNotHasKey('iban_fingerprint', $row);
    }

    public function test_recording_the_signature_takes_a_member_out_of_the_print(): void
    {
        $open = $this->member();
        $this->assertCount(1, $this->members->findOpenMandates([$open['id']]));

        $this->members->updateById($open['id'], ['mandate_signed_at' => '2026-11-28']);

        $this->assertSame([], $this->members->findOpenMandates([$open['id']]));
    }

    // ── what is on the page ──────────────────────────────────────────────

    public function test_the_blank_print_carries_no_iban(): void
    {
        $iban = $this->uniqueIban();
        $open = $this->member(['iban' => $iban]);

        $result = $this->service->render([$open['id']], null, null);

        $text = $this->text($result->pdf);
        $this->assertStringNotContainsString($this->grouped($iban), $text);
        $this->assertStringContainsString('endet auf ****' . substr($iban, -4), $text);
        $this->assertSame(3, (new MandateDocumentFiller())->pageCount($result->pdf));
    }

    /**
     * The whole path: the IBAN was sealed by a real write, is read back
     * through `findSealedIban()`, opened with the private half, and drawn.
     */
    public function test_the_keyed_print_carries_the_iban_that_was_sealed(): void
    {
        $first = $this->uniqueIban();
        $second = $this->uniqueIban();
        $a = $this->member(['iban' => $first]);
        $b = $this->member(['iban' => $second]);

        $result = $this->service->render([$a['id'], $b['id']], $this->devIbanOpener(), null);

        $text = $this->text($result->pdf);
        $this->assertStringContainsString($this->grouped($first), $text);
        $this->assertStringContainsString($this->grouped($second), $text);
        $this->assertSame(2, $result->sheetCount);
        $this->assertSame(0, $result->withoutIbanCount);
        $this->assertSame(6, (new MandateDocumentFiller())->pageCount($result->pdf), 'Three pages, two members.');
    }

    /**
     * No ENUM value was added for this (decision 6). These two inserts are the
     * proof that none was needed: both actions already exist in the column,
     * and both are accepted with `member` as the entity type.
     */
    public function test_both_prints_are_recorded_without_a_new_audit_action(): void
    {
        $open = $this->member();

        $this->service->render([$open['id']], null, null);
        $this->service->render([$open['id']], $this->devIbanOpener(), null);

        $stmt = $this->db->prepare(
            'SELECT action, entity_type, new_values FROM audit_log WHERE entity_id = ? ORDER BY action'
        );
        $stmt->execute([$open['id']]);
        $entries = $stmt->fetchAll();

        $this->assertSame(['iban_full_view', 'registration_printed'], array_column($entries, 'action'));
        $this->assertSame(['member', 'member'], array_column($entries, 'entity_type'));

        foreach ($entries as $entry) {
            $values = json_decode((string) $entry['new_values'], true);
            $this->assertMatchesRegularExpression('/^\*{4}\d{4}$/', $values['iban'], 'Masked, on both.');
        }
    }

    public function test_nobody_waiting_is_refused_by_name(): void
    {
        $signed = $this->member(['mandate_signed_at' => '2026-01-15']);

        try {
            $this->service->render([$signed['id']], null, null);
            $this->fail('A print with nobody in it must be refused.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::NO_OPEN_MANDATES, $e->getReason());
        }
    }
}
