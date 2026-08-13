<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

/**
 * The active key's 365-day cryptoperiod is over (ADR-0035). Hard stop for
 * sealing new IBANs and for SEPA export; key management stays available so
 * the admin can always rotate out of this state.
 */
class EncryptionKeyExpiredException extends \RuntimeException
{
}
