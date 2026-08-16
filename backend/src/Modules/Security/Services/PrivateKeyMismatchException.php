<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

/**
 * A supplied private key is malformed, or belongs to a different keypair than
 * the one it was offered for (ADR-0036).
 *
 * It extends \InvalidArgumentException so the callers that already map that to
 * a 422 `private_key_mismatch` — the SEPA export and rotate-batch — keep
 * working untouched. What the subclass adds is somewhere it has to be told
 * apart: activation looks a key up *and* validates a key against it, and both
 * failures were previously the same type, so "no such key" and "wrong key out
 * of the safe" would have collapsed into one status code.
 */
class PrivateKeyMismatchException extends \InvalidArgumentException
{
}
