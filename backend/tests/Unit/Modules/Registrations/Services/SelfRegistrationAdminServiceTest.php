<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Services;

use App\Modules\Registrations\Documents\MandateDocumentFiller;
use App\Modules\Registrations\Documents\TemplateFetcher;
use App\Modules\Registrations\Documents\UnusableTemplateException;
use App\Modules\Registrations\Domain\PosterSecret;
use App\Modules\Registrations\Repositories\SelfRegistrationConfigRepository;
use App\Modules\Registrations\Services\SelfRegistrationAdminService;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Logging\Logger;
use App\Shared\Security\SymmetricSecretBox;
use App\Shared\Services\AuditService;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The club's control over its own registration surface (#783, UC-A69).
 *
 * The config row is real — `sqlite::memory:` with a copy of migration `059`'s
 * table — so the writes are the statements that will actually run. The fetch is
 * faked, because no test opens a socket, and the fill is real so that a template
 * refusal here is the same refusal a member would have met.
 */
final class SelfRegistrationAdminServiceTest extends TestCase
{
    private const ADMIN = '44444444-4444-4444-4444-444444444444';

    private PDO $db;
    private SelfRegistrationConfigRepository $config;
    private StubTemplateFetcher $fetcher;
    private AuditService&MockObject $audit;
    private SelfRegistrationAdminService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec(
            'CREATE TABLE self_registration_config (
                id INTEGER NOT NULL PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0,
                disabled_reason VARCHAR(500) NULL, secret_hash CHAR(64) NULL,
                secret_cipher TEXT NULL, secret_rotated_at DATETIME NULL,
                retention_days INTEGER NOT NULL DEFAULT 30,
                updated_by_admin_id CHAR(36) NULL, created_at DATETIME NULL, updated_at DATETIME NULL
            )'
        );
        $this->db->exec('INSERT INTO self_registration_config (id, enabled) VALUES (1, 0)');

        $this->db->exec('CREATE TABLE sepa_config (id INTEGER PRIMARY KEY, mandate_template_url VARCHAR(500) NULL, updated_at DATETIME NULL)');
        $this->db->exec('INSERT INTO sepa_config (id, mandate_template_url) VALUES (1, NULL)');

        $logger = new Logger(sys_get_temp_dir() . '/self-registration-admin-tests', 'CRITICAL');

        $this->config = new SelfRegistrationConfigRepository($this->db);
        $this->fetcher = new StubTemplateFetcher(
            (string) file_get_contents(__DIR__ . '/../../../../Fixtures/documents/club-anmeldung.pdf')
        );
        $this->audit = $this->createMock(AuditService::class);

        $this->service = new SelfRegistrationAdminService(
            $this->config,
            new SepaConfigRepository($this->db, $logger),
            $this->fetcher,
            new MandateDocumentFiller(),
            new SymmetricSecretBox(str_repeat('ab', 32), 'TEST_KEY'),
            $this->audit,
            $logger,
        );
    }

    private function documentUrl(): ?string
    {
        return $this->db->query('SELECT mandate_template_url FROM sepa_config WHERE id = 1')
            ->fetch()['mandate_template_url'] ?? null;
    }

    // ── the secret ───────────────────────────────────────────────────────

    /**
     * The hash verifies a presented secret; the cipher lets the same poster be
     * reprinted. Writing one without the other leaves a club that can either
     * verify a secret it cannot show, or show one it cannot verify.
     */
    public function test_rotating_stores_both_forms_of_the_secret(): void
    {
        $secret = $this->service->rotateSecret(self::ADMIN);

        $row = $this->db->query('SELECT * FROM self_registration_config WHERE id = 1')->fetch();
        self::assertSame(PosterSecret::hash($secret), $row['secret_hash']);
        self::assertNotNull($row['secret_cipher']);
        self::assertNotSame($secret, $row['secret_cipher'], 'The cipher must not be the secret in the clear.');
        self::assertNotNull($row['secret_rotated_at']);
    }

    public function test_the_secret_can_be_read_back_for_a_reprint(): void
    {
        $secret = $this->service->rotateSecret(self::ADMIN);

        self::assertSame($secret, $this->service->currentSecret());
    }

    /**
     * Reprinting is not rotating, and the difference matters: a club that had
     * to rotate in order to reprint would invalidate every poster in the
     * building every time somebody spilled a drink on one.
     */
    public function test_reprinting_does_not_change_the_secret(): void
    {
        $secret = $this->service->rotateSecret(self::ADMIN);

        self::assertSame($secret, $this->service->currentSecret());
        self::assertSame($secret, $this->service->currentSecret());
    }

    /** Rotation is total: the old secret stops working the moment it commits. */
    public function test_rotating_replaces_the_previous_secret(): void
    {
        $first = $this->service->rotateSecret(self::ADMIN);
        $second = $this->service->rotateSecret(self::ADMIN);

        self::assertNotSame($first, $second);
        self::assertSame($second, $this->service->currentSecret());

        $hash = $this->db->query('SELECT secret_hash FROM self_registration_config WHERE id = 1')->fetchColumn();
        self::assertFalse(PosterSecret::matches($first, (string) $hash), 'The old poster must be dead.');
        self::assertTrue(PosterSecret::matches($second, (string) $hash));
    }

    public function test_reprinting_without_a_secret_says_so(): void
    {
        try {
            $this->service->currentSecret();
            self::fail('There is nothing to print.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::REGISTRATION_NO_SECRET, $e->getReason());
        }
    }

    /** No secret material in the entry — only that a rotation happened. */
    public function test_rotation_is_audited_without_the_secret(): void
    {
        $recorded = [];
        $this->audit->method('log')->willReturnCallback(function (...$args) use (&$recorded): void {
            $recorded[] = $args;
        });

        $secret = $this->service->rotateSecret(self::ADMIN);

        self::assertSame(AuditAction::REGISTRATION_SECRET_ROTATED, $recorded[0][0]);
        self::assertStringNotContainsString($secret, json_encode($recorded));
    }

    // ── the switch ───────────────────────────────────────────────────────

    /**
     * Two preconditions, each refused **by name**. A greyed-out switch that will
     * not say why is a support call; "generate a secret first" is a next step.
     */
    public function test_enabling_without_a_secret_names_the_missing_secret(): void
    {
        $this->db->exec("UPDATE sepa_config SET mandate_template_url = 'https://club.example/a.pdf'");

        try {
            $this->service->setAvailability(true, null, self::ADMIN);
            self::fail('There is no poster to enable.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::REGISTRATION_NO_SECRET, $e->getReason());
        }
    }

    /**
     * The Art. 13 precondition: without a document there is nothing to show
     * somebody before taking their name, birth date and IBAN.
     */
    public function test_enabling_without_a_document_names_the_missing_document(): void
    {
        $this->service->rotateSecret(self::ADMIN);

        try {
            $this->service->setAvailability(true, null, self::ADMIN);
            self::fail('There is nothing to show an applicant.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::DOCUMENT_URL_MISSING, $e->getReason());
        }

        self::assertFalse($this->config->get()->enabled, 'A refused enable must leave the switch off.');
    }

    public function test_enabling_succeeds_once_both_preconditions_hold(): void
    {
        $this->service->rotateSecret(self::ADMIN);
        $this->service->setDocumentUrl('https://club.example/Anmeldung.pdf', self::ADMIN);

        $this->service->setAvailability(true, null, self::ADMIN);

        self::assertTrue($this->config->get()->enabled);
    }

    /**
     * A club that is off without a sentence to show is the blank wall decision 1
     * exists to prevent — the person reading it is standing in the clubhouse
     * holding the club's own poster.
     */
    public function test_disabling_without_a_reason_is_refused(): void
    {
        try {
            $this->service->setAvailability(false, '   ', self::ADMIN);
            self::fail('A pause needs words.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::REGISTRATION_REASON_REQUIRED, $e->getReason());
        }
    }

    public function test_disabling_stores_the_reason_as_given(): void
    {
        $this->service->setAvailability(false, '  Beta-Phase schon voll  ', self::ADMIN);

        $config = $this->config->get();
        self::assertFalse($config->enabled);
        self::assertSame('Beta-Phase schon voll', $config->disabledReason);
    }

    /** Re-enabling clears the stale sentence rather than leaving it to be shown again. */
    public function test_enabling_clears_the_reason(): void
    {
        $this->service->rotateSecret(self::ADMIN);
        $this->service->setDocumentUrl('https://club.example/Anmeldung.pdf', self::ADMIN);
        $this->service->setAvailability(false, 'Beta-Phase schon voll', self::ADMIN);

        $this->service->setAvailability(true, null, self::ADMIN);

        self::assertNull($this->config->get()->disabledReason);
    }

    /**
     * The reason is a public statement the club made to its members, so it *is*
     * in the audit payload — unlike a secret. "Who said what, and when" is
     * exactly what this log is for.
     */
    public function test_the_pause_and_its_reason_are_audited(): void
    {
        $recorded = [];
        $this->audit->method('log')->willReturnCallback(function (...$args) use (&$recorded): void {
            $recorded[] = $args;
        });

        $this->service->setAvailability(false, 'Beta-Phase schon voll', self::ADMIN);

        self::assertSame(AuditAction::REGISTRATION_DISABLED, $recorded[0][0]);
        self::assertStringContainsString('Beta-Phase schon voll', json_encode($recorded));
    }

    // ── the document URL ─────────────────────────────────────────────────

    /**
     * The change this endpoint makes: the column has existed since migration
     * `028` and has never been checked. A URL that is wrong today surfaces as a
     * member's registration silently arriving without a document, weeks later,
     * with nothing connecting the two.
     */
    public function test_a_usable_document_is_accepted_and_stored(): void
    {
        $this->service->setDocumentUrl('https://club.example/Anmeldung.pdf', self::ADMIN);

        self::assertSame('https://club.example/Anmeldung.pdf', $this->documentUrl());
        self::assertSame('https://club.example/Anmeldung.pdf', $this->fetcher->lastUrl);
    }

    public function test_a_document_that_cannot_be_fetched_is_refused_and_not_stored(): void
    {
        $this->fetcher->body = null;

        try {
            $this->service->setDocumentUrl('https://club.example/gone.pdf', self::ADMIN);
            self::fail('An unfetchable URL must be refused.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::DOCUMENT_TEMPLATE_UNREACHABLE, $e->getReason());
        }

        self::assertNull($this->documentUrl(), 'A refused URL must not be saved.');
    }

    /**
     * The same refusal a member would have met, raised while the admin is
     * looking at the field — which is the entire point of validating here.
     */
    public function test_a_document_missing_a_required_field_is_refused_and_not_stored(): void
    {
        $this->fetcher->body = str_replace('(iban_last4)', '(iban_lastvier)', (string) $this->fetcher->body);

        try {
            $this->service->setDocumentUrl('https://club.example/wrong.pdf', self::ADMIN);
            self::fail('An incomplete template must be refused.');
        } catch (UnusableTemplateException $e) {
            self::assertSame(['iban_last4'], $e->missingFields);
        }

        self::assertNull($this->documentUrl());
    }

    public function test_something_that_is_not_a_pdf_is_refused_and_not_stored(): void
    {
        $this->fetcher->body = '<html>404 Not Found</html>';

        $this->expectException(UnusableTemplateException::class);

        try {
            $this->service->setDocumentUrl('https://club.example/404.html', self::ADMIN);
        } finally {
            self::assertNull($this->documentUrl());
        }
    }

    /**
     * Clearing the URL switches the club off, rather than leaving the
     * submission endpoint to refuse one applicant at a time. The two are the
     * same decision, and a club reading as "on" while refusing everybody is the
     * state that generates the support call.
     */
    public function test_clearing_the_document_switches_registration_off(): void
    {
        $this->service->rotateSecret(self::ADMIN);
        $this->service->setDocumentUrl('https://club.example/Anmeldung.pdf', self::ADMIN);
        $this->service->setAvailability(true, null, self::ADMIN);

        $this->service->setDocumentUrl('', self::ADMIN);

        self::assertNull($this->documentUrl());
        self::assertFalse($this->config->get()->enabled);
    }

    /** Clearing is allowed without a fetch: there is nothing to check. */
    public function test_clearing_the_document_needs_no_fetch(): void
    {
        $this->service->setDocumentUrl('', self::ADMIN);

        self::assertNull($this->fetcher->lastUrl);
    }

    // ── the settings payload ─────────────────────────────────────────────

    /**
     * No secret material in the settings, at all. An admin who wants the secret
     * asks for the poster, which is a separate action — a payload carrying a
     * live credential would put it in every page load and every screen share.
     */
    public function test_the_settings_carry_no_secret_material(): void
    {
        $secret = $this->service->rotateSecret(self::ADMIN);

        $settings = $this->service->settings()->toArray();

        self::assertTrue($settings['has_secret']);
        self::assertNotNull($settings['secret_rotated_at']);

        $payload = json_encode($settings);
        self::assertStringNotContainsString($secret, $payload);
        self::assertStringNotContainsString(PosterSecret::hash($secret), $payload);
        self::assertArrayNotHasKey('secret_cipher', $settings);
    }

    public function test_a_fresh_installation_reads_as_off_with_no_secret(): void
    {
        $settings = $this->service->settings()->toArray();

        self::assertFalse($settings['enabled']);
        self::assertFalse($settings['has_secret']);
        self::assertNull($settings['document_url']);
        self::assertSame(30, $settings['retention_days']);
    }
}

/** Answers from memory and remembers what it was asked. */
final class StubTemplateFetcher implements TemplateFetcher
{
    public ?string $lastUrl = null;

    public function __construct(public ?string $body) {}

    public function fetch(string $url, int $timeoutSeconds = 10): ?string
    {
        $this->lastUrl = $url;

        return $this->body;
    }
}
