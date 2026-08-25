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
}
