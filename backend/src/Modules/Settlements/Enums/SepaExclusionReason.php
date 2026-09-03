<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Enums;

/**
 * Why a member the settlement covers got no line in the pain.008 file (#114).
 *
 * The export builds the bank file from a settlement that was validated when it
 * was created, but the member data it needs is read *again* at export time and
 * can have changed in between. Every one of those cases used to be a bare
 * `continue`: the file collected less than the settlement recorded, the
 * settlement kept its full `total_amount_cents`, and nobody was told.
 *
 * Ruling #141 §3 is that an exclusion is reported in its own bucket with its
 * own reason, never folded into one warning list and never dropped — the
 * remedies differ. A missing mandate is chased with the member; a credit is
 * paid back or left to ride.
 */
enum SepaExclusionReason: string
{
    /** The member's net position is a credit: the club owes *them*. */
    case CREDIT_BALANCE = 'credit_balance';

    /** The mandate that existed at settlement creation is gone or has no IBAN. */
    case NO_ACTIVE_MANDATE = 'no_active_mandate';

    /** The member was deleted (GDPR erasure, offboarding) after the run was created. */
    case MEMBER_DELETED = 'member_deleted';

    /**
     * The mandate has an IBAN and a reference but no signature date, so there
     * is nothing truthful to put in `DtOfSgntr`.
     *
     * Its own case rather than a shade of {@see self::NO_ACTIVE_MANDATE},
     * because the remedy is different and much smaller: the bank details are
     * already there and correct, and somebody has to read a date off a signed
     * form and type it in. Told "no active SEPA mandate", a treasurer goes
     * looking for a missing IBAN they will not find.
     */
    case NO_MANDATE_DATE = 'no_mandate_date';

    public function label(): string
    {
        return match ($this) {
            self::CREDIT_BALANCE => 'In credit — the club owes this member money',
            self::NO_ACTIVE_MANDATE => 'No active SEPA mandate at export time',
            self::MEMBER_DELETED => 'Member record was deleted after the settlement was created',
            self::NO_MANDATE_DATE => 'Mandate has no signature date — the date the member signed is required for DtOfSgntr',
        };
    }

    /**
     * Whether this exclusion means money the settlement recorded will not be
     * collected.
     *
     * A credit is not a shortfall: the settlement never counted it as
     * collectable in the first place, because ruling #141 keeps credit members
     * out of the run entirely. The other two are — the settlement's total says
     * the club is owed the money, and the file does not ask for it.
     */
    public function isShortfall(): bool
    {
        return $this !== self::CREDIT_BALANCE;
    }
}
