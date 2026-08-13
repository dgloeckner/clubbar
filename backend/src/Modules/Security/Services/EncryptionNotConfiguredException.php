<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Shared\Exceptions\AppException;

/** No active IBAN encryption key is registered — IBAN writes and SEPA exports are blocked. */
class EncryptionNotConfiguredException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getErrorCode(): string
    {
        return 'encryption_not_configured';
    }
}
