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
}

/**
 * A mandate counts as complete when an IBAN *and* a reference exist
 * (`MemberAdminDto::fromRow`). A blank reference in the form is not a missing
 * one — the server generates it (`members.mandateReferenceHint`) — so it only
 * fails the check when there is no stored mandate to inherit either.
 */
export function deriveSepaFormStatus(input: SepaFormStatusInput): SepaFormStatus {
  const { savedIsValid, hasStoredIban, removalPending, typedIban, mandateReference } = input

  // Removal wins over everything else: the field is hidden while it is pending,
  // so there is no typed IBAN that could argue the other way.
  if (removalPending) {
    // Nothing to revoke on a member who never had bank details.
    return hasStoredIban || savedIsValid ? 'willBecomeInvalid' : 'missing'
  }

  if (savedIsValid) {
    return 'valid'
  }

  // Not valid as saved — would this submit fix that? It needs an IBAN (typed
  // now, or already stored and merely not accompanied by a reference) and a
  // reference (typed now, or auto-assigned).
  const willHaveIban = hasStoredIban || validateIban(typedIban)
  const willHaveReference = mandateReference.trim().length > 0 || willHaveIban

  return willHaveIban && willHaveReference ? 'willBecomeValid' : 'missing'
}
