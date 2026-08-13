<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Security\IbanSealedBox;
use PHPUnit\Framework\TestCase;

class IbanSealedBoxTest extends TestCase
{
    private const FINGERPRINT_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** The dev fingerprint key published in docker-compose.yml. */
    private const PUBLISHED_FINGERPRINT_KEY = '0000000000000000000000000000000000000000000000000000000000000002';

    /** The dev public key published in the repository (e2e fixtures). */
    private const PUBLISHED_PUBLIC_KEY_HEX = '7479840773cdbd0f57bacf5c8488818e55845ee19207aaf685b74869c1682155';

    private IbanSealedBox $box;
    private string $publicKey;
    private string $secretKey;

    protected function setUp(): void
    {
        $this->box = new IbanSealedBox(self::FINGERPRINT_KEY, 'test');

        $keypair = sodium_crypto_box_keypair();
        $this->publicKey = sodium_crypto_box_publickey($keypair);
        $this->secretKey = sodium_crypto_box_secretkey($keypair);
    }

    public function testSealOpenRoundtrip(): void
    {
        $sealed = $this->box->seal('DE89370400440532013000', $this->publicKey);

        $this->assertStringStartsWith('v1:', $sealed);
        $this->assertSame('DE89370400440532013000', $this->box->open($sealed, $this->secretKey));
    }

    public function testSealNormalizesBeforeEncrypting(): void
    {
        $sealed = $this->box->seal('de89 3704 0044 0532 0130 00', $this->publicKey);

        $this->assertSame('DE89370400440532013000', $this->box->open($sealed, $this->secretKey));
    }

    public function testSealIsRandomized(): void
    {
        $a = $this->box->seal('DE89370400440532013000', $this->publicKey);
        $b = $this->box->seal('DE89370400440532013000', $this->publicKey);

        $this->assertNotSame($a, $b, 'Sealed boxes must be randomized — equal ciphertexts would leak equality');
    }

    public function testOpenRejectsTamperedCiphertext(): void
    {
        $sealed = $this->box->seal('DE89370400440532013000', $this->publicKey);

        $raw = base64_decode(substr($sealed, 3));
        $raw[10] = chr(ord($raw[10]) ^ 0xFF);
        $tampered = 'v1:' . base64_encode($raw);

        $this->expectException(\RuntimeException::class);
        $this->box->open($tampered, $this->secretKey);
    }

    public function testOpenRejectsWrongKey(): void
    {
        $sealed = $this->box->seal('DE89370400440532013000', $this->publicKey);
        $otherSecret = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());

        $this->expectException(\RuntimeException::class);
        $this->box->open($sealed, $otherSecret);
    }

    public function testOpenRejectsUnknownVersionPrefix(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('version');
        $this->box->open('v9:' . base64_encode('nonsense'), $this->secretKey);
    }

    public function testOpenRejectsPlaintext(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->box->open('DE89370400440532013000', $this->secretKey);
    }

    public function testFingerprintIsDeterministicAndFormatIndependent(): void
    {
        $a = $this->box->fingerprint('DE89370400440532013000');
        $b = $this->box->fingerprint('de89 3704 0044 0532 0130 00');

        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }

    public function testFingerprintDependsOnKey(): void
    {
        $otherBox = new IbanSealedBox(str_repeat('bb', 32), 'test');

        $this->assertNotSame(
            $this->box->fingerprint('DE89370400440532013000'),
            $otherBox->fingerprint('DE89370400440532013000'),
        );
    }

    public function testIsEncryptedDetectsPrefix(): void
    {
        $this->assertTrue(IbanSealedBox::isEncrypted('v1:abc'));
        $this->assertTrue(IbanSealedBox::isEncrypted('v12:abc'));
        $this->assertFalse(IbanSealedBox::isEncrypted('DE89370400440532013000'));
        $this->assertFalse(IbanSealedBox::isEncrypted(null));
        $this->assertFalse(IbanSealedBox::isEncrypted(''));
    }

    public function testLastFour(): void
    {
        $this->assertSame('3000', IbanSealedBox::lastFour('de89 3704 0044 0532 0130 00'));
    }

    public function testPublicKeyFromSecretMatchesKeypair(): void
    {
        $this->assertSame($this->publicKey, $this->box->publicKeyFromSecret($this->secretKey));
    }

    public function testRejectsPublishedFingerprintKeyInProduction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('development key');
        new IbanSealedBox(self::PUBLISHED_FINGERPRINT_KEY, 'production');
    }

    public function testAcceptsPublishedFingerprintKeyInDevelopment(): void
    {
        $box = new IbanSealedBox(self::PUBLISHED_FINGERPRINT_KEY, 'test');
        $this->assertInstanceOf(IbanSealedBox::class, $box);
    }

    public function testRejectsShortFingerprintKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IBAN_FINGERPRINT_KEY');
        new IbanSealedBox('abcd', 'test');
    }

    public function testRejectsEmptyFingerprintKey(): void
    {
        $this->expectException(\RuntimeException::class);
        new IbanSealedBox('', 'test');
    }

    public function testRejectsPublishedPublicKeyInProduction(): void
    {
        // Constructor must not see the published fingerprint key here, only the
        // published PUBLIC key path is under test.
        $box = new IbanSealedBox(self::FINGERPRINT_KEY, 'production');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('development keypair');
        $box->rejectPublishedPublicKey(hex2bin(self::PUBLISHED_PUBLIC_KEY_HEX));
    }

    public function testAcceptsPublishedPublicKeyInDevelopment(): void
    {
        $this->box->rejectPublishedPublicKey(hex2bin(self::PUBLISHED_PUBLIC_KEY_HEX));
        $this->addToAssertionCount(1);
    }

    public function testSealRejectsWrongPublicKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->box->seal('DE89370400440532013000', 'short');
    }
}
