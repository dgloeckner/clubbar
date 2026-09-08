/**
 * The one canonical spelling of an RFID card UID.
 *
 * `members.card_uid` is matched by exact string comparison, so the spelling a
 * UID is stored in decides whether the card works at the terminal. The
 * volunteer assigning a card (ADR-0021) types or pastes it from whatever they
 * had to hand — the label on the card, a reader's diagnostic window, a note
 * from the last committee meeting — and those print the same 4-byte chip as:
 *
 * | `001EB4CB`    | uppercase hex, the canonical form |
 * | `001eb4cb`    | lower case |
 * | `00:1E:B4:CB` | grouped by byte, also `-`, `.` or spaces |
 * | `0x001EB4CB`  | prefixed, as a diagnostic tool prints it |
 * | `0002012363`  | the same value in decimal |
 *
 * Store whichever arrived and two things go wrong. The card works only until
 * somebody swaps the reader — and the uniqueness check does not notice that
 * `001EB4CB` and `00:1E:B4:CB` are one card being handed to two members.
 *
 * The backend applies the same rules on the way in, and is the authority; these
 * exist so the form can show the volunteer the value that will actually be
 * stored, before they save it.
 */

/** Fewest and most bytes a card UID has (ADR-0014's card technologies). */
const MIN_BYTES = 4
const MAX_BYTES = 10

/** The canonical spelling, and the only thing the column may hold. */
export const CARD_UID_PATTERN = new RegExp(`^(?:[0-9A-F]{2}){${MIN_BYTES},${MAX_BYTES}}$`)

/** Characters a reader or a tool groups bytes with. They carry no information. */
const SEPARATORS = /[\s:\-._]/g

/** What the field accepts while it is being typed, before canonicalization. */
const TYPEABLE = /[^0-9A-Fa-fXx:\-._ ]/g

export function isCanonicalCardUid(value: string): boolean {
  return CARD_UID_PATTERN.test(value)
}

/**
 * Reduce one printed spelling of a hex UID to the canonical form, or return
 * null when the input is not a card UID at all.
 *
 * A half-written byte (an odd digit count) is completed — `01EB4CB` is the same
 * chip as `001EB4CB`. Whole missing bytes are deliberately *not* invented:
 * input here comes from fingers, so `ABCD` is somebody who stopped typing, not
 * a three-byte card, and it is refused so the format message says so. The
 * terminal pads a short *scan* to four bytes, because a reader has no fingers.
 */
export function canonicalizeCardUid(raw: string): string | null {
  let cleaned = raw.trim().replace(SEPARATORS, '')
  if (/^0[xX]/.test(cleaned)) cleaned = cleaned.slice(2)

  if (cleaned === '' || !/^[0-9A-Fa-f]+$/.test(cleaned)) return null

  let canonical = cleaned.toUpperCase()
  if (canonical.length % 2 === 1) canonical = `0${canonical}`

  return isCanonicalCardUid(canonical) ? canonical : null
}

/**
 * What the field keeps while the volunteer is still typing.
 *
 * Deliberately permissive: stripping to `[0-9A-F]` as they type would silently
 * turn a pasted `0x001EB4CB` into `0001EB4CB` — the `x` gone, the `0` left
 * behind, and a UID one nibble adrift that still looks plausible. Everything is
 * kept until there is a whole value to canonicalize.
 */
export function sanitizeCardUidInput(raw: string): string {
  return raw.replace(TYPEABLE, '').toUpperCase()
}

/**
 * The canonical UID a decimal reader's output stands for, or null.
 *
 * Never applied automatically. `0002012363` is the decimal spelling of
 * `001EB4CB` *and* a perfectly good 5-byte hex UID, and nothing in the string
 * says which — so this is offered to the volunteer as a conversion they confirm
 * ({@link looksLikeDecimalCardUid}), never guessed at on their behalf. A wrong
 * guess files a member under a UID no reader will ever produce, and the only
 * symptom is a card that does not work.
 */
export function cardUidFromDecimal(raw: string): string | null {
  const digits = raw.trim().replace(SEPARATORS, '')
  if (!/^[0-9]+$/.test(digits)) return null

  let hex = BigInt(digits).toString(16).toUpperCase()
  if (hex.length % 2 === 1) hex = `0${hex}`
  hex = hex.padStart(MIN_BYTES * 2, '0')

  return isCanonicalCardUid(hex) ? hex : null
}

/**
 * Whether it is worth offering the decimal reading of what was entered.
 *
 * Only when the entry is all digits and the two readings actually differ: for
 * `00000012` the decimal and hex readings are the same chip, so there is
 * nothing to ask about.
 */
export function looksLikeDecimalCardUid(raw: string): boolean {
  const asHex = canonicalizeCardUid(raw)
  const asDecimal = cardUidFromDecimal(raw)

  return asDecimal !== null && asDecimal !== asHex
}
