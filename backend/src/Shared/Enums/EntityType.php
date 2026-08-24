<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum EntityType: string
{
    case MEMBER = 'member';
    case CATEGORY = 'category';
    case PRODUCT = 'product';
    case TRANSACTION = 'transaction';
    case ADMIN_USER = 'admin_user';
    case TERMINAL = 'terminal';
    case SETTLEMENT = 'settlement';
    case SEPA_CONFIG = 'sepa_config';
    case INSTANCE_CONFIG = 'instance_config';
    case MAIL_CONFIG = 'mail_config';
    case CREDIT_LIMIT_CONFIG = 'credit_limit_config';
    case ENCRYPTION_KEY = 'encryption_key';

    /**
     * The Bundesbank BLZ lookup table, as a whole (ADR-0049, #690).
     *
     * The entity is the table rather than a row: a re-import replaces all of
     * it, and naming one of ~20k bank codes as the subject would be arbitrary.
     */
    case BANK_CODES = 'bank_codes';

    /**
     * A backup recipient key, identified by its fingerprint (ADR-0049).
     *
     * Deliberately not {@see self::ENCRYPTION_KEY}: that is the IBAN keypair,
     * and the whole point of decision 2 is that these two are different keys
     * held by different offices. Sharing an entity type would make the audit
     * log the one place they looked the same.
     */
    case BACKUP_KEY = 'backup_key';
}
