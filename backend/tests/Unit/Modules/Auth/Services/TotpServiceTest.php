<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Services;

use App\Modules\Auth\Services\ChillerlanQrProvider;
use App\Modules\Auth\Services\TotpService;
use App\Shared\Config\Env;
use PHPUnit\Framework\TestCase;
use RobThree\Auth\TwoFactorAuth;

class TotpServiceTest extends TestCase
{
    // A 32-byte (64 hex char) key, distinct from the .env.example default, used
    // whenever a test just needs *a* valid key rather than the default one.
    private const VALID_KEY = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    // Same value published in backend/.env.example — anyone who reads the repo knows it.
    private const DOT_ENV_EXAMPLE_DEFAULT_KEY = '0000000000000000000000000000000000000000000000000000000000000001';

    protected function tearDown(): void
    {
        unset($_ENV['TOTP_ENCRYPTION_KEY'], $_ENV['APP_ENV']);
        Env::reset();
    }

    private function service(string $key = self::VALID_KEY): TotpService
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = $key;
        return new TotpService();
    }

    /** Independent TwoFactorAuth instance for generating codes to feed into TotpService::verifyCode(). */
    private function referenceAuthenticator(): TwoFactorAuth
    {
        return new TwoFactorAuth(new ChillerlanQrProvider(), 'Test');
    }

    public function test_constructor_throws_when_encryption_key_is_missing(): void
    {
        unset($_ENV['TOTP_ENCRYPTION_KEY']);

        $this->expectException(\RuntimeException::class);
        new TotpService();
    }

    public function test_constructor_throws_when_encryption_key_is_not_valid_hex(): void
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = 'not-a-hex-string!!';

        // hex2bin() returns false on invalid input; assigning that to the
        // string-typed $encryptionKey property fails under strict_types.
        $this->expectException(\TypeError::class);
        new TotpService();
    }

    public function test_constructor_accepts_the_dot_env_example_default_key_without_any_warning(): void
    {
        // Documents the current gap: nothing rejects the well-known example key,
        // even though anyone who has read the public repo can decrypt with it.
        $service = $this->service(self::DOT_ENV_EXAMPLE_DEFAULT_KEY);

        $encrypted = $service->encrypt('JBSWY3DPEHPK3PXP');
        $this->assertSame('JBSWY3DPEHPK3PXP', $service->decrypt($encrypted));
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

        $otherKey = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';
        $result = $this->service($otherKey)->decrypt($encrypted);

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $result);
    }
}
