<?php

declare(strict_types=1);

namespace App\Modules\CreditLimits\Domain;

/**
 * Where a Deckel sits relative to the ceiling that applies to it.
 *
 * Mirrors the terminal's `CreditLimitStatus` enum
 * (`terminal-frontend/lib/models/credit_limit.dart`) case for case, because the
 * two sides describe the same three positions to the same people — the member
 * at the bar and the Kassenwart reading the panel.
 *
 * The values are the strings the API has always sent; they are part of the
 * dashboard and Deckelauszug contracts, not an internal detail.
 */
enum CreditLimitStatus: string
{
    /** Comfortably inside the ceiling — say nothing. */
    case OK = 'ok';

    /** Inside the ceiling but in the warning band — tell them, keep serving. */
    case APPROACHING = 'approaching';

    /** Past the ceiling — the terminal refuses the next checkout. */
    case EXCEEDED = 'exceeded';
}
