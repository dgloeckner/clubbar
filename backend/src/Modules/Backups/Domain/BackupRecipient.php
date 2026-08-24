<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * One key an archive may be sealed to.
 *
 * A label and a public key, nothing else. The private half is not on this
 * server and never will be — that is the property the whole design exists for
 * (ADR-0049 decision 2).
 */
final class BackupRecipient
{
    public function __construct(
        public readonly string $label,
        public readonly string $publicKeyHex,
    ) {
    }

    /** The raw 32 bytes. */
    public function publicKey(): string
    {
        return (string) sodium_hex2bin($this->publicKeyHex);
    }

    /** As the archive header carries it, and as `backup_keys.fingerprint` stores it. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->publicKey());
    }

    /**
     * The shape {@see \App\Shared\Security\BackupSealedBox::seal()} takes —
     * raw bytes, not hex, which is the one thing easy to get wrong here: hex
     * would be 64 bytes and refused with a length error rather than a
     * type error.
     *
     * @return array{label: string, public_key: string}
     */
    public function toSealRecipient(): array
    {
        return ['label' => $this->label, 'public_key' => $this->publicKey()];
    }
}
