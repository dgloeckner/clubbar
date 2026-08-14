<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * What a queued message is (ADR-0038).
 *
 * The value is half of the outbox's uniqueness key —
 * `UNIQUE (kind, settlement_id, member_id)` — which is why a settlement can
 * carry both an announcement and, later, a cancellation notice for the same
 * member without either displacing the other.
 */
enum MailKind: string
{
    /** The SEPA pre-notification: creditor ID, mandate reference, amount, due date, statement. */
    case SEPA_PRENOTIFICATION = 'sepa_prenotification';

    /** „Einzug entfällt" — sent only to members whose announcement actually went out. */
    case CANCELLATION_NOTICE = 'cancellation_notice';

    /**
     * Reserved for #410: `bank_transfer` settlements need a payment request
     * (amount, club bank details, payment reference) and explicitly **no**
     * mandate reference or creditor ID. Nothing enqueues this yet; the value
     * exists so the schema does not have to change when it does.
     */
    case PAYMENT_REQUEST = 'payment_request';
}
