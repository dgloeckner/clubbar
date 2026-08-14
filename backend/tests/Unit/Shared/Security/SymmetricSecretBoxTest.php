<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Security\SymmetricSecretBox;
use PHPUnit\Framework\TestCase;

class SymmetricSecretBoxTest extends TestCase
{
    private const KEY = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    private SymmetricSecretBox $box;

    protected function setUp(): void
    {
        $this->box = new SymmetricSecretBox(self::KEY, 'SOME_KEY');
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $encrypted = $this->box->encrypt('JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('v1:', $encrypted);
        $this->assertSame('JBSWY3DPEHPK3PXP', $this->box->decrypt($encrypted));
    }

    public function testEncryptUsesARandomNonceSoCiphertextDiffersEachCall(): void
    {
        $this->assertNotSame($this->box->encrypt('SAMESECRET'), $this->box->encrypt('SAMESECRET'));
    }

    public function testDecryptReturnsFalseForInputWithoutTheVersionPrefix(): void
    {
        $this->assertFalse($this->box->decrypt('not-a-secretbox-ciphertext'));
    }

    public function testDecryptReturnsFalseForMalformedBase64(): void
    {
        $this->assertFalse($this->box->decrypt('v1:not-valid-base64!!!'));
    }

    public function testDecryptReturnsFalseForTruncatedInput(): void
    {
        $this->assertFalse($this->box->decrypt('v1:' . base64_encode('too-short')));
    }

    public function testDecryptOfTamperedCiphertextIsRejected(): void
    {
        // Unlike the AES-256-CBC path it replaced, secretbox authenticates —
        // tampering is always detected, not merely unlikely to reproduce the
        // original plaintext.
        $encrypted = $this->box->encrypt('JBSWY3DPEHPK3PXP');
        $raw = base64_decode(substr($encrypted, 3), true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
        $tampered = 'v1:' . base64_encode($raw);

        $this->assertFalse($this->box->decrypt($tampered));
    }

    public function testDecryptWithADifferentKeyThanItWasEncryptedWithFails(): void
    {
        $encrypted = $this->box->encrypt('JBSWY3DPEHPK3PXP');

        $otherBox = new SymmetricSecretBox(str_repeat('f', 64), 'SOME_KEY');

        $this->assertFalse($otherBox->decrypt($encrypted));
    }

    public function testConstructorRejectsAWrongLengthKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOME_KEY');
        new SymmetricSecretBox(str_repeat('a', 63), 'SOME_KEY');
    }
}
