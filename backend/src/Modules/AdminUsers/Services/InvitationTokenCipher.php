<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Services;

use App\Shared\Config\Env;
use App\Shared\Security\SymmetricSecretBox;

/**
 * Seals an invitation token so the mail drain can read it back
 * (migration 058).
 *
 * ## Why a sealed copy exists at all
 *
 * `admin_user_invitations.token_hash` is a one-way digest, which is what makes
 * a database dump useless — and also what makes the token unrecoverable. The
 * message carrying the link is rendered by the drain at send time, from live
 * data (ADR-0038 rule 5), minutes or hours after the row was queued, so
 * something has to survive in between that the server can read.
 *
 * ## Why this key
 *
 * `TOTP_ENCRYPTION_KEY` names the first thing it protected rather than what it
 * is: the installation's server-held symmetric key, for secrets this server
 * must be able to decrypt itself — the trust model `SymmetricSecretBox`'s class
 * comment sets out, and the opposite of `IbanSealedBox`, whose whole point is
 * that the server *cannot* read what it stores.
 *
 * An invitation token is that same kind of secret. A second variable would read
 * better and behave worse: every existing installation would have to generate
 * and configure it, and until they did, the first invitation would fail — at
 * exactly the moment somebody is onboarding a colleague, with a fatal about a
 * missing key rather than anything about invitations. The name is a smaller
 * cost than that, and it is only a name.
 *
 * Not `final`: the constructor reads the key from the environment, so a unit
 * test with no business configuring one doubles this instead.
 */
class InvitationTokenCipher
{
    private SymmetricSecretBox $secretBox;

    public function __construct()
    {
        $this->secretBox = new SymmetricSecretBox(Env::get('TOTP_ENCRYPTION_KEY'), 'TOTP_ENCRYPTION_KEY');
    }

    public function seal(string $token): string
    {
        return $this->secretBox->encrypt($token);
    }

    /**
     * Returns false for a tampered, truncated or wrong-key ciphertext —
     * secretbox authenticates, so this is a detected failure rather than
     * plausible garbage. The mail builder refuses to send rather than mailing
     * a link that leads nowhere.
     */
    public function open(string $sealed): string|false
    {
        return $this->secretBox->decrypt($sealed);
    }
}
