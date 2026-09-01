<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

/**
 * Who is printing, which decides one thing: whether the IBAN is machine-printed
 * (#780, ADR-0052 decision 5).
 *
 * The debtor IBAN is mandatory *mandate content* under the EPC SDD Core
 * Rulebook. It is not mandatory *machine-printed* content, and that distinction
 * is the whole reason ADR-0036 needs no exception here: the admin never has a
 * plaintext IBAN to print, because nobody on the server does.
 */
enum MandateDocumentVariant: string
{
    /**
     * Printed by the member, during their own submission, from the plaintext
     * still in memory in that one request. There is no second chance: the
     * moment that request ends the plaintext is gone, sealed under a key this
     * server does not hold.
     */
    case MEMBER = 'member';

    /**
     * Printed by the Kassenwart at review. The IBAN line is left **blank** for
     * the member to write by hand at signature, and `iban_last4` carries the
     * `endet auf ****3000` hint the admin checks it against.
     *
     * This is the variant that always works — it needs no plaintext at all — so
     * a member whose phone lost the tab is never stuck.
     */
    case ADMIN_PRINT = 'admin_print';
}
