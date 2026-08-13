<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

/** No active IBAN encryption key is registered — IBAN writes and SEPA exports are blocked. */
class EncryptionNotConfiguredException extends \RuntimeException
{
}
