/**
 * SEPA mandate status as the *member form* should show it.
 *
 * The member resource carries `is_sepa_valid`, but that is the saved state. The
 * form can hold changes that will flip it on save — removing the stored bank
 * details revokes the mandate, typing an IBAN for a member who has none makes
 * one possible — and a banner that keeps announcing the saved state while the
 * field below it says the opposite is the bug this replaces (#392).
 */

import { validateIban } from './iban'

export type SepaFormStatus =
  | 'valid'
  | 'missing'
  /** Unsaved: the form would make this member SEPA-valid. */
  | 'willBecomeValid'
  /** Unsaved: the form would revoke this member's mandate. */
  | 'willBecomeInvalid'

export interface SepaFormStatusInput {
  /** `is_sepa_valid` as the API returned it for the member being edited. */
  savedIsValid: boolean
  /** The member has bank details on file (`iban_last4` is set). */
  hasStoredIban: boolean
  /** "Remove bank details" was clicked and not undone. */
  removalPending: boolean
  /** The IBAN currently typed into the form, if the field is showing. */
  typedIban: string
  /** The mandate reference field; blank means the server assigns one. */
  mandateReference: string
  /**
   * The mandate signature date as the form currently holds it (ISO, or '').
   *
   * Prefilled from the stored value when the form opens, so an untouched form
   * carries what the member has — which makes blanking it a real, previewable
   * change rather than an invisible one.
   */
  mandateSignedAt: string
}

/**
 * A mandate counts as complete when an IBAN, a reference **and a signature
 * date** all exist (`MandateCompleteness`, ADR-0020 as amended for #164).
 *
 * The date is the part that used to be missing here, and the omission was not
 * cosmetic: the banner said "SEPA-Mandat gültig" over an empty Mandatsdatum,
 * and the export then filled `DtOfSgntr` with the settlement's own date. The
 * form has to preview the rule the server actually applies, or it goes back to
 * promising a collection that will be refused.
 *
 * A blank *reference* in the form is not a missing one — the server generates
 * it (`members.mandateReferenceHint`) — so it only fails the check when there
 * is no stored mandate to inherit either. A blank *date* has no such escape:
 * nothing can derive when a member signed.
 */
export function deriveSepaFormStatus(input: SepaFormStatusInput): SepaFormStatus {
  const { savedIsValid, hasStoredIban, removalPending, typedIban, mandateReference, mandateSignedAt } =
    input

  // Removal wins over everything else: the field is hidden while it is pending,
  // so there is no typed IBAN that could argue the other way.
  if (removalPending) {
    // Nothing to revoke on a member who never had bank details.
    return hasStoredIban || savedIsValid ? 'willBecomeInvalid' : 'missing'
  }

  const willHaveDate = mandateSignedAt.trim().length > 0

  if (savedIsValid) {
    // Clearing the date revokes the mandate as surely as removing the account
    // does — it is one of the three parts — so it gets the same warning rather
    // than a "valid" banner standing over the field being emptied (#392).
    return willHaveDate ? 'valid' : 'willBecomeInvalid'
  }

  // Not valid as saved — would this submit fix that? It needs an IBAN (typed
  // now, or already stored and merely not accompanied by a reference), a
  // reference (typed now, or auto-assigned), and the signature date.
  const willHaveIban = hasStoredIban || validateIban(typedIban)
  const willHaveReference = mandateReference.trim().length > 0 || willHaveIban

  return willHaveIban && willHaveReference && willHaveDate ? 'willBecomeValid' : 'missing'
}
