<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Shared\Exceptions\AppException;

/**
 * The active key's 365-day cryptoperiod is over (ADR-0035). Hard stop for
 * sealing new IBANs and for SEPA export; key management stays available so
 * the admin can always rotate out of this state.
 */
class EncryptionKeyExpiredException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getErrorCode(): string
    {
        return 'encryption_key_expired';
    }
}
