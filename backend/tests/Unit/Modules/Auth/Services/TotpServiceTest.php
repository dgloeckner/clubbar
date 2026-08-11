<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Services;

use App\Modules\Auth\Services\ChillerlanQrProvider;
use App\Modules\Auth\Services\TotpService;
use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\Config\Env;
use PHPUnit\Framework\TestCase;
use RobThree\Auth\TwoFactorAuth;

class TotpServiceTest extends TestCase
{
    // A 32-byte (64 hex char) key, distinct from the .env.example default, used
    // whenever a test just needs *a* valid key rather than the default one.
    private const VALID_KEY = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    // The fixed development key. It is in docker-compose.yml and in db/seed.sql,
    // so anyone who reads the repo knows it — which is the whole reason a
    // deployment must not run on it.
    private const PUBLISHED_DEVELOPMENT_KEY = '0000000000000000000000000000000000000000000000000000000000000001';

    /** The process-level key, if the surrounding environment set one. */
    private ?string $processKey = null;

    protected function setUp(): void
    {
        $inherited = getenv('TOTP_ENCRYPTION_KEY');
        $this->processKey = $inherited === false ? null : $inherited;
    }

    protected function tearDown(): void
    {
        unset($_ENV['TOTP_ENCRYPTION_KEY'], $_ENV['APP_ENV']);
        $this->restoreProcessKey();
        Env::reset();
    }

    /**
     * Hide the key from every source `Env::get()` consults.
     *
     * Clearing `$_ENV` alone is not enough: `Env::get()` falls back to
     * `getenv()`, and docker-compose sets TOTP_ENCRYPTION_KEY as a real process
     * variable. So this test used to pass only where the variable happened to be
     * absent — on CI, which runs PHPUnit on the host — and fail in the container,
     * which is where CLAUDE.md tells everyone to run backend tests.
     */
    private function hideEncryptionKey(): void
    {
        unset($_ENV['TOTP_ENCRYPTION_KEY']);
        putenv('TOTP_ENCRYPTION_KEY');
        Env::reset();
    }

    /** Put the inherited process variable back, so later tests still see it. */
    private function restoreProcessKey(): void
    {
        putenv(
            $this->processKey === null
                ? 'TOTP_ENCRYPTION_KEY'
                : 'TOTP_ENCRYPTION_KEY=' . $this->processKey
        );
    }

    /**
     * APP_ENV is pinned rather than inherited: docker-compose sets it to `local`
     * for the backend container and CI leaves it unset (which reads as
     * production), and the published-key rule turns on exactly that difference.
     */
    private function service(string $key = self::VALID_KEY, string $appEnv = 'local'): TotpService
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = $key;
        $_ENV['APP_ENV'] = $appEnv;
        return new TotpService($this->instanceConfigService());
    }

    /** A stub with no DB access — TotpService only needs the instance name. */
    private function instanceConfigService(string $instanceName = 'Test Club'): InstanceConfigService
    {
        $stub = $this->createStub(InstanceConfigService::class);
        $stub->method('getInstanceName')->willReturn($instanceName);
        return $stub;
    }

    /** Independent TwoFactorAuth instance for generating codes to feed into TotpService::verifyCode(). */
    private function referenceAuthenticator(): TwoFactorAuth
    {
        return new TwoFactorAuth(new ChillerlanQrProvider(), 'Test');
    }

    public function test_constructor_throws_when_encryption_key_is_missing(): void
    {
        $this->hideEncryptionKey();

        $this->expectException(\RuntimeException::class);
        new TotpService($this->instanceConfigService());
    }

    public function test_constructor_throws_when_encryption_key_is_not_valid_hex(): void
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = str_repeat('z', 64);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TOTP_ENCRYPTION_KEY');
        new TotpService($this->instanceConfigService());
    }

    /**
     * openssl pads a short key with NUL to reach the 32 bytes AES-256 wants, so
     * a truncated key silently weakens every stored secret instead of failing.
     *
     * @dataProvider unusableKeys
     */
    public function test_constructor_rejects_a_key_that_is_not_thirty_two_bytes(string $keyHex): void
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = $keyHex;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TOTP_ENCRYPTION_KEY');
        new TotpService($this->instanceConfigService());
    }

    /** @return array<string, array{string}> */
    public static function unusableKeys(): array
    {
        return [
            // What package/index.php stores when config.php has no
            // security.totp_encryption_key: every such install would otherwise
            // share a key of 32 NUL bytes.
            'empty' => [''],
            'half length' => [str_repeat('a', 32)],
            'one hex char short' => [str_repeat('a', 63)],
            'one hex char long' => [str_repeat('a', 65)],
        ];
    }

    // ── The published development key (#107) ───────────────

    public function test_constructor_rejects_the_published_development_key_in_production(): void
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = self::PUBLISHED_DEVELOPMENT_KEY;
        $_ENV['APP_ENV'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('openssl rand -hex 32');
        new TotpService($this->instanceConfigService());
    }

    /**
     * An operator who never sets APP_ENV is deploying, not developing — the
     * default has to be the strict side of the rule, or the check is opt-in for
     * exactly the installs that need it.
     */
    public function test_constructor_rejects_the_published_key_when_app_env_is_unset(): void
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = self::PUBLISHED_DEVELOPMENT_KEY;
        unset($_ENV['APP_ENV']);
        putenv('APP_ENV');
        Env::reset();

        $this->expectException(\RuntimeException::class);
        new TotpService($this->instanceConfigService());
    }

    /** @dataProvider developmentEnvironments */
    public function test_constructor_accepts_the_published_key_in_development(string $appEnv): void
    {
        // seed.sql pre-encrypts the test admin's TOTP secret with this key, so
        // the dev stack and the E2E suite genuinely need it to work.
        $service = $this->service(self::PUBLISHED_DEVELOPMENT_KEY, $appEnv);

        $this->assertSame('JBSWY3DPEHPK3PXP', $service->decrypt($service->encrypt('JBSWY3DPEHPK3PXP')));
    }

    /** @return array<string, array{string}> */
    public static function developmentEnvironments(): array
    {
        return [
            // What docker-compose.yml sets.
            'local' => ['local'],
            // What docker-compose.ci.yml sets.
            'test' => ['test'],
            'development' => ['development'],
            // Case is not the operator's problem.
            'Local, capitalised' => ['Local'],
        ];
    }

    public function test_a_self_generated_key_is_accepted_in_production(): void
    {
        $service = $this->service(self::VALID_KEY, 'production');

        $this->assertSame('JBSWY3DPEHPK3PXP', $service->decrypt($service->encrypt('JBSWY3DPEHPK3PXP')));
    }

    // ── Issuer reflects the configured instance name (ADR-0034) ───────────

    /** The otpauth:// issuer, read via reflection since it is only exposed through the QR image. */
    private function issuerOf(TotpService $service): string
    {
        $tfa = (new \ReflectionProperty($service, 'tfa'))->getValue($service);
        $qrText = $tfa->getQRText('someone@example.com', 'JBSWY3DPEHPK3PXP');
        parse_str((string) parse_url($qrText, PHP_URL_QUERY), $params);
        return $params['issuer'];
    }

    public function test_issuer_uses_the_configured_instance_name_in_production(): void
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = self::VALID_KEY;
        $_ENV['APP_ENV'] = 'production';
        $service = new TotpService($this->instanceConfigService('FRGS Ruderbar'));

        $this->assertSame('FRGS Ruderbar', $this->issuerOf($service));
    }

    public function test_issuer_appends_dev_suffix_outside_production(): void
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = self::VALID_KEY;
        $_ENV['APP_ENV'] = 'local';
        $service = new TotpService($this->instanceConfigService('FRGS Ruderbar'));

        $this->assertSame('FRGS Ruderbar (dev)', $this->issuerOf($service));
    }

    public function test_generateSecret_returns_a_nonempty_base32_secret(): void
    {
        $secret = $this->service()->generateSecret();

        $this->assertNotEmpty($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_verifyCode_accepts_a_currently_valid_code(): void
    {
        $service = $this->service();
        $secret = $service->generateSecret();
        $code = $this->referenceAuthenticator()->getCode($secret);

        $this->assertTrue($service->verifyCode($secret, $code));
    }

    public function test_verifyCode_accepts_the_same_code_again_no_replay_protection(): void
    {
        // Documents the current gap: TotpService has no state, so a captured
        // code can be replayed as many times as it stays within the window.
        $service = $this->service();
        $secret = $service->generateSecret();
        $code = $this->referenceAuthenticator()->getCode($secret);

        $this->assertTrue($service->verifyCode($secret, $code));
        $this->assertTrue($service->verifyCode($secret, $code));
    }

    public function test_verifyCode_rejects_a_code_outside_the_time_window(): void
    {
        $service = $this->service();
        $secret = $service->generateSecret();
        // 150s away is five 30s periods from now — outside the default ±1 window.
        $staleCode = $this->referenceAuthenticator()->getCode($secret, time() + 150);

        $this->assertFalse($service->verifyCode($secret, $staleCode));
    }

    public function test_verifyCode_rejects_wrong_secret(): void
    {
        $service = $this->service();
        $code = $this->referenceAuthenticator()->getCode($service->generateSecret());

        $this->assertFalse($service->verifyCode($service->generateSecret(), $code));
    }

    /** @dataProvider malformedCodeProvider */
    public function test_verifyCode_rejects_malformed_input(string $code): void
    {
        $service = $this->service();
        $secret = $service->generateSecret();

        $this->assertFalse($service->verifyCode($secret, $code));
    }

    public static function malformedCodeProvider(): array
    {
        return [
            'empty' => [''],
            'too short' => ['123'],
            'non numeric' => ['abcdef'],
            'too long' => ['1234567890'],
        ];
    }

    public function test_encrypt_decrypt_round_trip(): void
    {
        $service = $this->service();

        $encrypted = $service->encrypt('JBSWY3DPEHPK3PXP');

        $this->assertStringContainsString(':', $encrypted);
        $this->assertSame('JBSWY3DPEHPK3PXP', $service->decrypt($encrypted));
    }

    public function test_encrypt_uses_a_random_iv_so_ciphertext_differs_each_call(): void
    {
        $service = $this->service();

        $this->assertNotSame($service->encrypt('SAMESECRET'), $service->encrypt('SAMESECRET'));
    }

    public function test_decrypt_returns_false_for_input_without_a_colon_separator(): void
    {
        $this->assertFalse($this->service()->decrypt('not-in-the-iv-colon-ciphertext-format'));
    }

    public function test_decrypt_of_tampered_ciphertext_never_yields_the_original_secret(): void
    {
        // PKCS7-padding validation makes most tampered ciphertexts decrypt to
        // false, but that isn't guaranteed for arbitrary bytes — what matters
        // is that tampering can never recover the original plaintext.
        $service = $this->service();
        $encrypted = $service->encrypt('JBSWY3DPEHPK3PXP');
        [$iv, $ciphertext] = explode(':', $encrypted, 2);
        $tampered = $iv . ':' . base64_encode('garbage-not-real-ciphertext-bytes!!');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $service->decrypt($tampered));
    }

    public function test_decrypt_with_a_different_key_than_it_was_encrypted_with_fails(): void
    {
        $encrypted = $this->service(self::VALID_KEY)->encrypt('JBSWY3DPEHPK3PXP');

        $otherKey = str_repeat('f', 64);
        $result = $this->service($otherKey)->decrypt($encrypted);

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $result);
    }
}
