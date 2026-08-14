/**
 * Where a settlement stands, as the server said it stands (#377).
 *
 * The settlements page used to answer this for itself:
 *
 * ```ts
 * if (settlement.is_cancelled) return 'cancelled'
 * if (settlement.exported_at != null) return 'exported'
 * return 'active'
 * ```
 *
 * Six server states collapsed into three, so `submitted`, `partly_reversed`
 * and `fully_reversed` were unrenderable — and a run whose file had gone to the
 * bank looked exactly like one whose file had merely been generated. That is
 * the second rule set the #127 ruling warned about, and it is why this module
 * decides nothing: it reads `status`, which the backend derives on every read
 * from `is_cancelled`, the reversal rows, `submitted_at` and `exported_at`
 * (ruling #148 §6), and only maps it onto labels, colours and which buttons a
 * row offers.
 *
 * The one thing it does not read is `status_label`. That field is the server's
 * English rendering; the admin panel is translated, so the label comes from the
 * locale files keyed on `status` and `status_label` is the fallback for a value
 * this build has never heard of.
 */

import { theme } from '../styles/design-system'

/** The ladder, most-final first — the order the backend evaluates it in. */
export const SETTLEMENT_STATUSES = [
  'cancelled',
  'fully_reversed',
  'partly_reversed',
  'submitted',
  'exported',
  'draft',
] as const

export type SettlementStatusValue = (typeof SETTLEMENT_STATUSES)[number]

/** The fields of a settlement row this module reads. */
export interface SettlementStatusTarget {
  status?: string
  status_label?: string | null
  method?: string
}

/**
 * The row's status, or null when the payload carries none.
 *
 * Null is deliberately not "draft": a settlement whose status is unknown is
 * not a settlement that is known to be a draft, and guessing here would
 * re-introduce the client-side derivation this module exists to remove. The
 * badge falls back to `status_label` in that case, and every action gate
 * treats it as "offer nothing".
 */
export function settlementStatus(settlement: SettlementStatusTarget): SettlementStatusValue | null {
  const status = settlement.status
  return SETTLEMENT_STATUSES.includes(status as SettlementStatusValue)
    ? (status as SettlementStatusValue)
    : null
}

/** The locale key for a status badge, e.g. `settlements.status.submitted`. */
export function settlementStatusLabelKey(status: SettlementStatusValue): string {
  return `settlements.statusLabels.${status}`
}

/**
 * The badge colour. The ladder reads as a progression rather than a palette:
 * grey while nothing has happened, blue once a file exists, green once the
 * bank has it, amber and violet as money comes back, red when the run was
 * called off.
 */
export function settlementStatusColor(status: SettlementStatusValue | null): string {
  switch (status) {
    case 'cancelled':
      return theme.colors.semantic.danger
    case 'fully_reversed':
      return theme.colors.semantic.violet
    case 'partly_reversed':
      return theme.colors.semantic.amber
    case 'submitted':
      return theme.colors.semantic.emerald
    case 'exported':
      return theme.colors.semantic.primary
    case 'draft':
      return theme.colors.semantic.neutral
    default:
      return theme.colors.semantic.neutral
  }
}

/**
 * A file was generated for this run and nobody has said it reached the bank.
 *
 * Both the "mark as submitted" action and the awaiting-confirmation marker
 * hang off this one condition, because they are the two halves of the same
 * fact: `exported` already implies live, unreversed and not yet submitted —
 * every one of those would have outranked it on the ladder.
 *
 * The method test mirrors the backend, which 409s on anything but a direct
 * debit: a bank transfer or a write-off never goes to a bank at all.
 */
export function awaitsBankConfirmation(settlement: SettlementStatusTarget): boolean {
  return settlementStatus(settlement) === 'exported' && settlement.method === 'direct_debit'
}

/**
 * Whether a SEPA file may still be generated for this run.
 *
 * Only `draft` and `exported` — regenerating the file once it is with the bank
 * is how a double submission starts, and the button used to stay live through
 * submission and reversal alike. A row with no status offers nothing.
 */
export function canExportSepa(settlement: SettlementStatusTarget): boolean {
  const status = settlementStatus(settlement)
  return status === 'draft' || status === 'exported'
}
