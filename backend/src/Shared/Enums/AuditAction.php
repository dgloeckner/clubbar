<?php

declare(strict_types=1);

namespace App\Shared\Enums;

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
    /** A booking reversed in full (#169). The only admin-initiated transaction. */
    case TRANSACTION_STORNO = 'transaction_storno';
    case SETTLEMENT_CREATE = 'settlement_create';
    case SETTLEMENT_CANCEL = 'settlement_cancel';
    case SETTLEMENT_EXPORT = 'settlement_export';
    /** The exported file reached the bank — the point cancellation ends (#81). */
    case SETTLEMENT_SUBMIT = 'settlement_submit';
    case TOTP_ENROLLED = 'totp_enrolled';
    case TOTP_RESET = 'totp_reset';
    case MANDATE_DOCUMENT_UPLOAD = 'mandate_document_upload';
    case MANDATE_DOCUMENT_DELETE = 'mandate_document_delete';
}
