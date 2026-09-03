/**
 * What the member form still needs, and what saving it would take away.
 *
 * The form used to state both of those with a single character: a `*` glued to
 * five labels. That is enough to *look up* whether a field is mandatory once
 * you already suspect it might be, and no help at all for the two questions an
 * admin actually has — "what is still missing before this member can be
 * created?" and, on an edit, "what did I just take away?".
 *
 * Three tiers of field exist here and only the first is genuinely mandatory:
 *
 *  - **required** — the API refuses the member without it. First/last name,
 *    email, the ADR-0045 birth date, and the language (which always carries a
 *    value, so it is never actually missing — it is listed for completeness,
 *    not suspense).
 *  - **conditional** — optional to store, but a capability is off until it is
 *    there: the card UID gates terminal access, IBAN + mandate gate SEPA
 *    collection (ADR-0020). The form names the capability rather than calling
 *    the field "optional", which understates it.
 *  - **clearable** — values that are *stored*, so emptying the input on an edit
 *    deletes something. `handleSubmit` sends `null` for a blank one (#131),
 *    which is the silent half of this: nothing on screen said the save was a
 *    deletion.
 *
 * The tiers are not exclusive, and `mandate_signed_at` is in two of them: it
 * is clearable (blanking it deletes a stored date) *and* conditional (without
 * it the member has no collectable mandate — ADR-0020, #164 — so the SEPA
 * export excludes them and the terminal refuses the card). It is listed below
 * for the first, and carries the conditional marker in the form; being
 * clearable is why it must not simply become `required`, since a member with
 * no mandate at all is a legitimate state.
 *
 * This module is the pure half — no i18n, no React — so the rules can be
 * asserted directly rather than through the modal.
 */

/** Fields the API refuses a member without. */
export const MEMBER_REQUIRED_FIELDS = [
  'first_name',
  'last_name',
  'email',
  'date_of_birth',
  'preferred_language',
] as const

export type MemberRequiredField = (typeof MEMBER_REQUIRED_FIELDS)[number]

/**
 * Optional fields whose blank input reaches the API as an explicit `null`.
 *
 * The IBAN is deliberately absent: it cannot be prefilled (ADR-0036), so a
 * blank input there is the normal state of a save about something else and
 * means "keep" — removing it is its own action with its own confirmation
 * (#392). Comparing it against the stored value the way this list is compared
 * would flag every single edit as a deletion.
 *
 * The mandate reference is absent for the same reason in reverse: a blank one
 * is sent as `undefined` so the server keeps or mints it (ADR-0006).
 */
export const MEMBER_CLEARABLE_FIELDS = [
  'card_uid',
  'account_holder_name',
  'mandate_signed_at',
] as const

export type MemberClearableField = (typeof MEMBER_CLEARABLE_FIELDS)[number]

/** The subset of the form's state these rules read. */
export type MemberRequiredValues = Record<MemberRequiredField, string>

/** The saved member, as much of it as the clearing check needs. */
export type MemberStoredValues = Partial<Record<MemberClearableField, string | null | undefined>>

/**
 * A stored value this submit would delete: the member has one, the input is
 * now empty.
 */
export interface ClearedField {
  field: MemberClearableField
  /** The value that is about to go, for naming it in the warning. */
  previous: string
}

/** Whitespace is not an answer — the backend trims and then refuses it too. */
function isBlank(value: string | null | undefined): boolean {
  return (value ?? '').trim().length === 0
}

/**
 * Required fields with nothing in them, in the order they appear in the form —
 * so "jump to the first one that is missing" lands where the eye already is.
 */
export function missingRequiredFields(values: MemberRequiredValues): MemberRequiredField[] {
  return MEMBER_REQUIRED_FIELDS.filter((field) => isBlank(values[field]))
}

/** How many required fields carry a value, for the progress line. */
export function countSatisfiedRequired(values: MemberRequiredValues): number {
  return MEMBER_REQUIRED_FIELDS.length - missingRequiredFields(values).length
}

/**
 * Stored values this save would delete.
 *
 * Only meaningful while editing: on a create there is nothing stored, so the
 * caller passes no saved member and gets an empty list.
 */
export function clearedFields(
  stored: MemberStoredValues | null | undefined,
  values: Partial<Record<MemberClearableField, string>>,
): ClearedField[] {
  if (!stored) return []

  return MEMBER_CLEARABLE_FIELDS.flatMap((field) => {
    const previous = stored[field]
    if (isBlank(previous)) return []
    if (!isBlank(values[field])) return []
    return [{ field, previous: (previous as string).trim() }]
  })
}

/**
 * A deliberately loose email check — `something@something.tld`, no spaces.
 *
 * It exists because the form suppresses the browser's own validation to report
 * every missing field at once instead of one native bubble at a time
 * (`noValidate`), and dropping `type="email"`'s check along with the bubbles
 * would have traded one annoyance for a round trip. The authority is still the
 * backend, which owns the length limit and the uniqueness rule.
 */
export function isPlausibleEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value.trim())
}
