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
     * A pending self-registration (ADR-0052).
     *
     * Its own type rather than `member`, because at the moment an approval or a
     * rejection is recorded the member either does not exist yet or is about to
     * stop existing. The approval entry names the *registration* id, which is
     * what keeps a member's origin traceable after the pending row is deleted
     * — there is no other record that the person arrived through a poster.
     */
    case REGISTRATION = 'registration';
}
