/**
 * What a member can and cannot do with the Clubbar, derived from the form as it
 * currently stands (#830).
 *
 * The edit dialog used to answer this with four separate green things: a
 * requirements panel with a progress bar (#629), a SEPA alert below it (#392),
 * a "✓ Pflicht" pill on every satisfied label, and blue "→ erforderlich für …"
 * pills on the conditional ones. Between them they said a great deal about
 * *fields* and nothing about the three outcomes an admin actually opens the
 * dialog to check:
 *
 *   - can this member book at the terminal,
 *   - can their Deckel be collected by SEPA,
 *   - can the club reach them at all.
 *
 * So the strip is grouped by outcome, and a field only appears in it as the
 * *fix* for one — named, and linked, so "what is missing" and "what do I do
 * about it" are the same sentence.
 *
 * Three rules keep it honest:
 *
 *  1. **The gaps are `MEMBER_GAPS`.** The same four the roster's Datenqualität
 *     panel counts (`utils/memberCompleteness.ts`). If the dialog invented a
 *     fifth, or read one of the four differently, an admin could fix
 *     everything the dialog asks for and still see the roster call the member
 *     incomplete — which teaches them to trust neither.
 *  2. **Every tile previews the save; none reports the load.** `SepaFormStatus`
 *     has done this for the mandate since #392, and a strip where one tile
 *     means "after saving" while the two beside it mean "as loaded" would be
 *     worse than either rule applied consistently. So the terminal and mail
 *     tiles are compared against the saved member too, and say "sobald
 *     gespeichert" wherever this edit is what changes the answer.
 *  3. **Green is an outcome, never a tick count.** A tile is green because the
 *     member can do the thing, not because n of m fields carry a value.
 *
 * Pure — no i18n, no React. A tile carries the *key* of its sentence and the
 * parameters that sentence needs; `MemberStatusStrip` renders it. That is what
 * lets the rules be asserted directly instead of through the modal.
 */

import type { MemberGap } from './memberCompleteness'
import type { SepaFormStatus } from './sepaStatus'

export type MemberStatusTileId = 'terminal' | 'sepa' | 'reachable'

/**
 * How a tile reads, and therefore what colour it is.
 *
 *   ok      — green: the capability is on, and saving will not change that
 *   partial — orange: on, but reduced. A member with no birth date may book,
 *             just nothing with a `min_age` (ADR-0045) — a real restriction
 *             that one red/green light would either overstate or hide
 *   gap     — orange: off, and still off after saving
 *   pending — blue: off now, on once this form is saved
 *   losing  — red: on now, off once this form is saved
 */
export type MemberStatusTone = 'ok' | 'partial' | 'gap' | 'pending' | 'losing'

/** A field named in a tile, as the thing that would close the gap. */
export interface MemberStatusGapRef {
  /** The `registerField` key, handed to `onJumpTo`. */
  field: string
  /**
   * Key for the field's name, as the label above the input reads it.
   *
   * The roster's chips say "Karte"; a link inside the dialog has to say
   * "Karten-UID", because it is about to put the caret in a field with that
   * exact label. Same gap, two audiences.
   */
  labelKey: string
  /** Which roster gap this is, where it is one of the four. */
  gap?: MemberGap
}

export interface MemberStatusTile {
  id: MemberStatusTileId
  tone: MemberStatusTone
  /** Key into `members.status.*` for the one-line outcome. */
  messageKey: string
  /** ISO date for the SEPA tile's "Mandat gültig seit {{date}}"; absent otherwise. */
  since?: string
  /** The fields that would close this tile's gap, worst first. */
  gaps: MemberStatusGapRef[]
}

/** The three capabilities as the *saved* member has them, or null on a create. */
export interface MemberSavedCapabilities {
  hasCardUid: boolean
  hasDateOfBirth: boolean
  hasEmail: boolean
}

export interface MemberStatusInput {
  /** The card UID as typed; blank means the terminal cannot identify them. */
  cardUid: string
  /** ISO birth date as the form holds it (ADR-0045). */
  dateOfBirth: string
  email: string
  /** What *this submit* would do to the mandate — never the loaded state (#392). */
  sepa: SepaFormStatus
  /** Whether an IBAN will exist after saving: stored and kept, or typed and valid. */
  willHaveIban: boolean
  /** ISO signature date as the form holds it (#164). */
  mandateSignedAt: string
  /**
   * The member as the API returned them, or null while creating one.
   *
   * Null makes every capability "new", so a create reads "sobald gespeichert"
   * throughout rather than claiming a member who does not exist yet can
   * already book.
   */
  saved: MemberSavedCapabilities | null
}

function isBlank(value: string): boolean {
  return value.trim().length === 0
}

const CARD_UID_GAP: MemberStatusGapRef = {
  field: 'card_uid',
  labelKey: 'members.form.cardUid',
  gap: 'card_uid',
}

const DATE_OF_BIRTH_GAP: MemberStatusGapRef = {
  field: 'date_of_birth',
  labelKey: 'members.dateOfBirth',
  gap: 'date_of_birth',
}

const EMAIL_GAP: MemberStatusGapRef = {
  field: 'email',
  labelKey: 'members.email',
  gap: 'email',
}

/**
 * Terminal: can the member buy, and can they buy everything?
 *
 * The two fields fail differently and the tile has to say which. With no card
 * UID the terminal cannot identify them at all; with a card but no birth date
 * they are served, minus anything age-restricted.
 */
function terminalTile(input: MemberStatusInput): MemberStatusTile {
  const hasCard = !isBlank(input.cardUid)
  const hasBirthDate = !isBlank(input.dateOfBirth)

  const gaps: MemberStatusGapRef[] = []
  if (!hasCard) gaps.push(CARD_UID_GAP)
  if (!hasBirthDate) gaps.push(DATE_OF_BIRTH_GAP)

  const couldBookBefore = input.saved?.hasCardUid ?? false

  if (!hasCard) {
    return {
      id: 'terminal',
      tone: couldBookBefore ? 'losing' : 'gap',
      messageKey: couldBookBefore
        ? 'members.status.terminal.willLoseAccess'
        : 'members.status.terminal.noAccess',
      gaps,
    }
  }

  if (!hasBirthDate) {
    return { id: 'terminal', tone: 'partial', messageKey: 'members.status.terminal.ageLimited', gaps }
  }

  const couldBookEverythingBefore = couldBookBefore && (input.saved?.hasDateOfBirth ?? false)

  return couldBookEverythingBefore
    ? { id: 'terminal', tone: 'ok', messageKey: 'members.status.terminal.full', gaps: [] }
    : { id: 'terminal', tone: 'pending', messageKey: 'members.status.terminal.willGainAccess', gaps: [] }
}

/**
 * SEPA: will the Deckel be collectable after this save?
 *
 * `SepaFormStatus` has already applied the mandate rule (IBAN + reference +
 * signature date, ADR-0020 as amended for #164) to the form rather than to the
 * saved member, so this only has to name the parts that are still open. The
 * reference is never one of them: a blank one is minted by the server
 * (ADR-0006), so listing it would send an admin to fix a field that is not
 * broken.
 */
function sepaTile(input: MemberStatusInput): MemberStatusTile {
  const gaps: MemberStatusGapRef[] = []
  if (!input.willHaveIban) {
    gaps.push({ field: 'iban', labelKey: 'members.iban', gap: 'sepa' })
  }
  if (isBlank(input.mandateSignedAt)) {
    gaps.push({ field: 'mandate_signed_at', labelKey: 'members.mandateSignedAt', gap: 'sepa' })
  }

  switch (input.sepa) {
    case 'valid':
      return {
        id: 'sepa',
        tone: 'ok',
        messageKey: 'members.status.sepa.valid',
        since: input.mandateSignedAt,
        gaps: [],
      }
    case 'willBecomeValid':
      return { id: 'sepa', tone: 'pending', messageKey: 'members.status.sepa.willBecomeValid', gaps: [] }
    case 'willBecomeInvalid':
      // The gap list is what puts the mandate back — the IBAN that is being
      // removed, or the date that was just cleared — so a revocation reached
      // by accident is one click from being undone rather than a warning with
      // no handle on it.
      return { id: 'sepa', tone: 'losing', messageKey: 'members.status.sepa.willBecomeInvalid', gaps }
    case 'missing':
      return { id: 'sepa', tone: 'gap', messageKey: 'members.status.sepa.missing', gaps }
  }
}

/** Reachable: mail is the club's only channel (statements, announcements). */
function reachableTile(input: MemberStatusInput): MemberStatusTile {
  const hasEmail = !isBlank(input.email)
  const wasReachable = input.saved?.hasEmail ?? false

  if (hasEmail) {
    return wasReachable
      ? { id: 'reachable', tone: 'ok', messageKey: 'members.status.reachable.yes', gaps: [] }
      : { id: 'reachable', tone: 'pending', messageKey: 'members.status.reachable.willBecome', gaps: [] }
  }

  return {
    id: 'reachable',
    tone: wasReachable ? 'losing' : 'gap',
    messageKey: wasReachable ? 'members.status.reachable.willLose' : 'members.status.reachable.no',
    gaps: [EMAIL_GAP],
  }
}

/**
 * The three tiles, always all three and always in this order.
 *
 * A tile is never dropped for being green: the strip is a status display, and
 * one that only appears when something is wrong cannot be used to confirm that
 * nothing is.
 */
export function deriveMemberStatusTiles(input: MemberStatusInput): MemberStatusTile[] {
  return [terminalTile(input), sepaTile(input), reachableTile(input)]
}

/** Every field the strip currently names, deduplicated, in tile order. */
export function statusGapFields(tiles: MemberStatusTile[]): string[] {
  const seen = new Set<string>()
  for (const tile of tiles) {
    for (const gap of tile.gaps) seen.add(gap.field)
  }
  return [...seen]
}

/**
 * How many fields this edit has actually touched (#830).
 *
 * The footer says so beside *Speichern*, because the dialog's other half of
 * "what will this save do" — the strip — only reports the three capabilities.
 * A member whose name is being corrected changes nothing in the strip, and
 * "Keine Änderungen" is then the difference between pressing Speichern and
 * pressing Abbrechen.
 *
 * Compared against the form as it was *opened*, not against the API object:
 * the two date fields, the credit limit and the language all pass through a
 * normalisation on the way in, and comparing the normalised value against the
 * raw one would report a change on a form nobody typed into.
 *
 * `extraChanges` carries what is not in the field map — a pending IBAN
 * removal, or an IBAN typed to replace a stored one (ADR-0036 leaves the input
 * blank, so its own before/after is always blank → blank).
 */
export function countChangedFields(
  initial: Record<string, string> | null,
  current: Record<string, string>,
  extraChanges = 0,
): number {
  if (!initial) return 0

  const changed = Object.keys(current).filter(
    (key) => (initial[key] ?? '') !== (current[key] ?? ''),
  ).length

  return changed + extraChanges
}
