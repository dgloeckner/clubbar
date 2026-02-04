<?php

declare(strict_types=1);

namespace App\Enum;

enum AuditAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case ACTIVATE = 'activate';
    case DEACTIVATE = 'deactivate';
    case REORDER = 'reorder';
    case ANONYMIZE = 'anonymize';
    case LOGIN = 'login';
    case LOGOUT = 'logout';
    case LOGIN_FAILED = 'login_failed';
    case EXPORT = 'export';
    case SETTLEMENT_CREATE = 'settlement_create';
    case SETTLEMENT_CANCEL = 'settlement_cancel';
    case SETTLEMENT_EXPORT = 'settlement_export';
}
