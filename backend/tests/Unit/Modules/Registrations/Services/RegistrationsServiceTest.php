<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Services;

use App\Modules\Registrations\Domain\PosterSecret;
use App\Modules\Registrations\Repositories\RegistrationAttemptsRepository;
use App\Modules\Registrations\Repositories\RegistrationsRepository;
use App\Modules\Registrations\Repositories\SelfRegistrationConfigRepository;
use App\Modules\Registrations\Services\RegistrationsService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\TooManyAttemptsException;
use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Logging\Logger;
use App\Shared\Security\IbanSealedBox;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The gate, the sealing and the refusals.
 *
 * Runs against `sqlite::memory:` with hand-maintained copies of migration
 * `059`'s tables plus the two rows this service reads from elsewhere — the
 * active encryption key and the club's document URL.
 */
final class RegistrationsServiceTest extends TestCase
{
    private const SECRET = 'a-poster-secret-value-for-the-tests-0000000';
    private const IBAN = 'DE89370400440532013000';

    private PDO $db;
    private string $publicKey;
    private RegistrationsService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec(
            'CREATE TABLE pending_registrations (
                id CHAR(36) NOT NULL PRIMARY KEY,
                first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL, phone VARCHAR(20) NULL,
                date_of_birth DATE NOT NULL, preferred_language VARCHAR(10) NOT NULL,
                account_holder_name VARCHAR(70) NULL,
                mandate_reference VARCHAR(35) NOT NULL UNIQUE,
                iban_ciphertext BLOB NOT NULL, iban_last4 CHAR(4) NOT NULL,
                iban_fingerprint CHAR(64) NOT NULL, encryption_key_id CHAR(36) NOT NULL,
                bank_name VARCHAR(255) NULL, privacy_notice_url VARCHAR(500) NOT NULL,
                privacy_notice_shown_at DATETIME NOT NULL,
                submitted_at DATETIME NOT NULL, expires_at DATETIME NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE self_registration_config (
                id INTEGER NOT NULL PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0,
                disabled_reason VARCHAR(500) NULL, secret_hash CHAR(64) NULL,
                secret_cipher TEXT NULL, secret_rotated_at DATETIME NULL,
                retention_days INTEGER NOT NULL DEFAULT 30,
                updated_by_admin_id CHAR(36) NULL, created_at DATETIME NULL, updated_at DATETIME NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE registration_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ip_address VARCHAR(45) NOT NULL,
                outcome VARCHAR(10) NOT NULL, attempted_at DATETIME NOT NULL
            )'
        );

        $this->db->exec(
            "INSERT INTO self_registration_config (id, enabled, secret_hash)
             VALUES (1, 1, '" . PosterSecret::hash(self::SECRET) . "')"
        );

        // A real X25519 keypair: the service seals for real, and the test
        // asserts on the ciphertext it produces rather than on a stand-in.
        $keypair = sodium_crypto_box_keypair();
        $this->publicKey = sodium_crypto_box_publickey($keypair);

        $this->db->exec(
            'CREATE TABLE encryption_keys (
                id CHAR(36) PRIMARY KEY, key_identifier VARCHAR(100) NOT NULL,
                algorithm VARCHAR(50) NOT NULL, public_key BLOB NOT NULL,
                fingerprint_sha256 CHAR(64) NOT NULL, status VARCHAR(20) NOT NULL,
                activated_at DATETIME NULL, expires_at DATETIME NULL,
                created_at DATETIME NULL, created_by_admin_id CHAR(36) NULL
            )'
        );
        $stmt = $this->db->prepare(
            "INSERT INTO encryption_keys
                (id, key_identifier, algorithm, public_key, fingerprint_sha256, status, activated_at, expires_at)
             VALUES ('key-1', 'club-2026', 'SODIUM_CRYPTO_BOX_SEAL', ?, ?, 'active', '2026-01-01 00:00:00', NULL)"
        );
        $stmt->bindValue(1, $this->publicKey, PDO::PARAM_LOB);
        $stmt->bindValue(2, str_repeat('f', 64));
        $stmt->execute();

        $this->db->exec(
            'CREATE TABLE bank_codes (
                bank_code CHAR(8) NOT NULL PRIMARY KEY, bank_name VARCHAR(255) NOT NULL
            )'
        );
        $this->db->exec("INSERT INTO bank_codes (bank_code, bank_name) VALUES ('37040044', 'Sparkasse Musterstadt')");

        $this->db->exec('CREATE TABLE sepa_config (id INTEGER PRIMARY KEY, mandate_template_url VARCHAR(255) NULL)');
        $this->db->exec(
            "INSERT INTO sepa_config (id, mandate_template_url) VALUES (1, 'https://club.example/Anmeldung_Ruderbar.pdf')"
        );

        $logger = new Logger(sys_get_temp_dir() . '/registrations-tests', 'CRITICAL');

        $this->service = new RegistrationsService(
            new RegistrationsRepository($this->db),
            new SelfRegistrationConfigRepository($this->db),
            new RegistrationAttemptsRepository($this->db),
            new EncryptionKeysRepository($this->db, $logger),
            new BankCodeService(new BankCodesRepository($this->db, $logger), $logger),
            new SepaConfigRepository($this->db, $logger),
            new IbanSealedBox(str_repeat('ab', 32), 'testing'),
            $logger,
        );
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Lena',
            'last_name' => 'Brandt',
            'email' => 'lena@example.org',
            'date_of_birth' => '2010-04-02',
            'preferred_language' => 'de',
            'iban' => self::IBAN,
        ];
    }

    // --- the gate ------------------------------------------------------------

    /**
     * A wrong secret, a missing one, and a club that never generated one all
     * answer the same way. Anything that told them apart would confirm to a
     * prober that a valid secret exists.
     */
    public function test_a_wrong_or_missing_secret_is_a_uniform_refusal(): void
    {
        foreach (['definitely-not-the-secret', ''] as $presented) {
            try {
                $this->service->submit($presented, $this->payload(), '10.0.0.1');
                self::fail('Expected a refusal for secret: ' . var_export($presented, true));
            } catch (NotFoundException $e) {
                self::assertSame(404, $e->getHttpStatusCode());
            }
        }
    }

    public function test_a_club_with_no_secret_refuses_identically(): void
    {
        $this->db->exec('UPDATE self_registration_config SET secret_hash = NULL WHERE id = 1');

        $this->expectException(NotFoundException::class);
        $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');
    }

    /**
     * Disabled is the other answer, and it is deliberately different: the
     * person holding the poster is standing in the clubhouse, and they get the
     * club's own reason.
     */
    public function test_disabled_refuses_with_the_typed_reason_and_the_clubs_text(): void
    {
        $this->db->exec(
            "UPDATE self_registration_config SET enabled = 0, disabled_reason = 'Beta-Phase schon voll' WHERE id = 1"
        );

        try {
            $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');
            self::fail('Expected a refusal');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::REGISTRATION_DISABLED, $e->getReason());
            self::assertSame('Beta-Phase schon voll', $e->getParams()['reason']);
        }
    }

    /** Hiding the form is not the gate; the endpoint refuses on its own. */
    public function test_disabled_refuses_even_with_the_right_secret(): void
    {
        $this->db->exec('UPDATE self_registration_config SET enabled = 0 WHERE id = 1');

        $this->expectException(BusinessRuleException::class);
        $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');
    }

    // --- what gets written ---------------------------------------------------

    public function test_a_submission_is_sealed_and_stored(): void
    {
        $receipt = $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');

        $row = $this->db->query('SELECT * FROM pending_registrations')->fetch();

        self::assertSame($receipt->id, $row['id']);
        self::assertStringStartsWith('v1:', $row['iban_ciphertext']);
        self::assertSame('3000', $row['iban_last4']);
        self::assertSame(64, strlen($row['iban_fingerprint']));
        self::assertSame('key-1', $row['encryption_key_id']);
        self::assertSame('Sparkasse Musterstadt', $row['bank_name']);
    }

    /** The one guarantee the store exists to keep. */
    public function test_the_plaintext_iban_is_nowhere_in_the_row(): void
    {
        $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');

        $row = $this->db->query('SELECT * FROM pending_registrations')->fetch();
        foreach ($row as $column => $value) {
            self::assertStringNotContainsString(self::IBAN, (string) $value, "in {$column}");
        }
    }

    /**
     * Minted at submission because it is printed on the paper before the
     * mandate exists, in ADR-0006's format.
     */
    public function test_a_mandate_reference_is_minted_in_the_adr_0006_format(): void
    {
        $receipt = $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $receipt->mandateReference);
        self::assertLessThanOrEqual(35, strlen($receipt->mandateReference));
    }

    public function test_the_expiry_is_the_configured_retention_from_submission(): void
    {
        $this->db->exec('UPDATE self_registration_config SET retention_days = 30 WHERE id = 1');

        $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');

        $row = $this->db->query('SELECT submitted_at, expires_at FROM pending_registrations')->fetch();
        $days = (strtotime($row['expires_at']) - strtotime($row['submitted_at'])) / 86400;
        self::assertEqualsWithDelta(30, $days, 0.01);
    }

    /** The document the applicant was pointed at is recorded as shown. */
    public function test_the_document_url_shown_is_recorded(): void
    {
        $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');

        $row = $this->db->query('SELECT privacy_notice_url FROM pending_registrations')->fetch();
        self::assertSame('https://club.example/Anmeldung_Ruderbar.pdf', $row['privacy_notice_url']);
    }

    // --- no enumeration ------------------------------------------------------

    /**
     * A returning applicant gets the response a first-timer gets. The club
     * learns about the duplicate at review, where an authenticated admin is
     * entitled to know.
     */
    public function test_a_duplicate_submission_is_accepted_identically(): void
    {
        $first = $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');
        $second = $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');

        self::assertNotSame($first->id, $second->id);
        self::assertNotSame($first->mandateReference, $second->mandateReference);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM pending_registrations')->fetchColumn());
    }

    // --- the honeypot --------------------------------------------------------

    /**
     * The traffic this URL will actually attract is commodity form-fillers.
     * They get a success they cannot distinguish from a real one, and nothing
     * is stored.
     */
    public function test_a_filled_honeypot_looks_accepted_and_stores_nothing(): void
    {
        $receipt = $this->service->submit(
            self::SECRET,
            $this->payload(['website' => 'http://spam.example']),
            '10.0.0.1',
        );

        self::assertNotSame('', $receipt->id);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM pending_registrations')->fetchColumn());
    }

    // --- the meters ----------------------------------------------------------

    public function test_repeated_refusals_from_one_address_are_throttled(): void
    {
        for ($i = 0; $i < RegistrationsService::MAX_REFUSED_PER_WINDOW; $i++) {
            try {
                $this->service->submit('wrong', $this->payload(), '10.0.0.5');
            } catch (NotFoundException) {
                // counted
            }
        }

        // Even the *right* secret is now refused from that address — and as
        // "too many", never as "registration is closed", which would send the
        // member to the bar to ask about a policy that does not exist.
        $this->expectException(TooManyAttemptsException::class);
        $this->service->submit(self::SECRET, $this->payload(), '10.0.0.5');
    }

    /**
     * The meter the login surface does not have: somebody holding the real
     * secret never fails, and can still fill the queue.
     */
    public function test_repeated_accepted_submissions_are_throttled_too(): void
    {
        for ($i = 0; $i < RegistrationsService::MAX_ACCEPTED_PER_WINDOW; $i++) {
            $this->service->submit(self::SECRET, $this->payload(), '10.0.0.6');
        }

        $this->expectException(TooManyAttemptsException::class);
        $this->service->submit(self::SECRET, $this->payload(), '10.0.0.6');
    }

    public function test_one_addresss_budget_does_not_spend_anothers(): void
    {
        for ($i = 0; $i < RegistrationsService::MAX_ACCEPTED_PER_WINDOW; $i++) {
            $this->service->submit(self::SECRET, $this->payload(), '10.0.0.7');
        }

        $receipt = $this->service->submit(self::SECRET, $this->payload(), '10.0.0.8');
        self::assertNotSame('', $receipt->id);
    }

    // --- the purge -----------------------------------------------------------

    public function test_the_purge_deletes_expired_rows_and_returns_a_count(): void
    {
        $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');
        $this->db->exec("UPDATE pending_registrations SET expires_at = '2000-01-01 00:00:00'");
        $this->service->submit(self::SECRET, $this->payload(), '10.0.0.1');

        self::assertSame(1, $this->service->purgeExpired());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM pending_registrations')->fetchColumn());
    }
}
