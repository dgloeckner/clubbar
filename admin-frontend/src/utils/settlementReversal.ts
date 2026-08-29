/**
 * Which undo a settlement row actually offers (epic #433).
 *
 * `ReversalGate` is written as the exact mirror of `CancellationGate`:
 * `is_reversible` is false precisely when the settlement is still cancellable,
 * true otherwise. **Exactly one of the two is open for any live settlement** —
 * which is why the row has one slot, not two. The button reads *Rückgängig*
 * while the run can still be cancelled and becomes *Zurückbuchen* the moment it
 * cannot.
 *
 * That is what #81 was asking for. A treasurer reaching for "undo" on a
 * submitted run lands on the operation that is actually available, rather than
 * on a disabled control explaining what they may not do.
 *
 * Like {@link ./settlementUndo}, this module decides nothing about the gates
 * themselves: it reads `is_cancellable` and `is_reversible` as the backend
 * derived them. Re-deriving either here would be the second rule set #81 *is*.
 */

import type { UndoSettlementTarget } from './settlementUndo'

/** The fields of a settlement row this decision reads. */
export interface SettlementReversalTarget extends UndoSettlementTarget {
  /** The backend's answer — the mirror of `is_cancellable`. */
  is_reversible?: boolean
  reversal_blocked_reason?: string | null
  /** The same refusal as a code the client translates (#757). */
  reversal_blocked_code?: string | null
  reversal_blocked_params?: Record<string, unknown> | null
  /** How many of the settlement's members already came back. */
  reversed_member_count?: number
  member_count?: number
}

/**
 * What the row's single undo slot does.
 *
 * `none` is reached only by a cancelled settlement — nothing was ever
 * collected, so there is neither a cancellation left to make nor anything for a
 * bank to return.
 */
export type SettlementRowUndoAction = 'cancel' | 'reverse' | 'none'

export function settlementRowUndoAction(settlement: SettlementReversalTarget): SettlementRowUndoAction {
  // `=== true` rather than truthiness throughout: an absent flag is an older
  // payload, not a refusal.
  if (settlement.is_reversible === true) return 'reverse'
  if (settlement.is_cancelled === true) return 'none'

  // Cancellable, or a payload that says nothing either way. Offering cancel is
  // what this page has always done, and a settlement the gate refuses still
  // opens the dialog, which states the backend's reason (#127).
  return 'cancel'
}

/**
 * Every member of this run already came back, so a further reversal has nothing
 * left to name.
 *
 * Not a gate — `ReversalGate` neither knows nor cares how many members are left
 * — but arithmetic on two counts the row already carries. Offering the button
 * here would earn a guaranteed 409 that says the same thing later.
 */
export function allMembersReversed(settlement: SettlementReversalTarget): boolean {
  const reversed = settlement.reversed_member_count ?? 0
  const members = settlement.member_count ?? 0

  return members > 0 && reversed >= members
}
