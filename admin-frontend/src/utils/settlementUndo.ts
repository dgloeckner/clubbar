/**
 * What the Undo button on a settlement is actually about to do (#127).
 *
 * The button used to ask "Undo this settlement? All transactions will return
 * to Open state." — no date, no amount, no member count, and no hint that a
 * pain.008 file for this run had already been generated. Undoing a settlement
 * that the bank has collected debits every member a second time on the next
 * run, so the dialog is the last place that can still say what is at stake.
 *
 * The rule itself lives in the backend's `CancellationGate` and reaches the
 * client as `is_cancellable` + `cancellation_blocked_reason`; this module only
 * reads that answer. Deciding here whether a settlement may be cancelled would
 * be a second rule set, and #81 is exactly the bug where the two drifted.
 */

/** The fields of a settlement this decision reads. */
export interface UndoSettlementTarget {
  is_cancelled?: boolean
  /** The backend's answer. Absent (older payloads) means "not blocked". */
  is_cancellable?: boolean
  cancellation_blocked_reason?: string | null
  /**
   * The same refusal as a code the client translates (#757).
   * `cancellation_blocked_reason` is the backend's English sentence and is
   * kept only so an older payload still says *something*; the dialog renders
   * this code in the admin's own language.
   */
  cancellation_blocked_code?: string | null
  cancellation_blocked_params?: Record<string, unknown> | null
  /** Non-null once a SEPA file has been generated for this settlement. */
  exported_at?: string | null
  settlement_date?: string
  created_at?: string
}

export interface UndoDecision {
  /** The gate is shut: show the reason, offer no confirm button. */
  blocked: boolean
  /** The backend's own words, when it gave them. English — see below. */
  blockedReason: string | null
  /** What the dialog actually shows, once translated. */
  blockedCode: string | null
  blockedParams: Record<string, unknown> | null
  /**
   * A file exists for this settlement, so the admin has to state that it never
   * reached the bank before the confirm button unlocks. Exporting is
   * deliberately not a blocker (ruling #142 §1 — generating a file is not
   * sending one), which is precisely why it needs a conscious answer.
   */
  needsExportAcknowledgement: boolean
}

export function describeUndo(settlement: UndoSettlementTarget): UndoDecision {
  const blockedReason = settlement.cancellation_blocked_reason ?? null
  // `is_cancellable === false` rather than `!is_cancellable`: an absent flag
  // is an older payload, not a refusal, and must not disable the button.
  const blocked = settlement.is_cancelled === true || settlement.is_cancellable === false

  return {
    blocked,
    blockedReason: blocked ? blockedReason : null,
    blockedCode: blocked ? (settlement.cancellation_blocked_code ?? null) : null,
    blockedParams: blocked ? (settlement.cancellation_blocked_params ?? null) : null,
    needsExportAcknowledgement: !blocked && hasExport(settlement),
  }
}

/**
 * The date this settlement is known by. `settlement_date` is the run's own
 * date; `created_at` is what the list table shows and the only date rows
 * written before it existed carry.
 */
export function undoDisplayDate(settlement: UndoSettlementTarget): string {
  return settlement.settlement_date || settlement.created_at || ''
}

function hasExport(settlement: UndoSettlementTarget): boolean {
  return settlement.exported_at !== null && settlement.exported_at !== undefined
}
