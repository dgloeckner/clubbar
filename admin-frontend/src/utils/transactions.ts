/**
 * Transaction display utilities.
 * Extracted from services/transactions.ts.
 */

import { theme } from '../styles/design-system'

export function formatTransactionType(type: string): string {
  const labels: Record<string, string> = {
    purchase: 'Purchase',
    storno: 'Storno',
    payout: 'Payout',
  }
  return labels[type] ?? type
}

export function getTransactionTypeColor(
  type: string
): { bg: string; text: string } {
  const colors: Record<string, { bg: string; text: string }> = {
    purchase: { bg: 'rgba(59, 130, 246, 0.1)', text: theme.colors.semantic.primary },
    storno: { bg: 'rgba(251, 146, 60, 0.1)', text: theme.colors.semantic.warning },
    payout: { bg: 'rgba(168, 85, 247, 0.1)', text: theme.colors.semantic.violet },
  }
  return colors[type] ?? { bg: 'rgba(107, 114, 128, 0.1)', text: theme.colors.text.muted }
}

/**
 * Colour for a *balance* (the Deckel) — members table and member cards.
 *
 * Mirrors `balanceColor()` in the terminal's `design_tokens.dart`. See
 * ADR-0042 for the cross-app rule and the sign convention it rests on.
 *
 * `warnAtCents` is the tab from which **this member** is warned, and it
 * arrives from the API as `credit_limit_warn_at_cents` — it is never derived
 * here. The override-or-default rule and its integer-division rounding are
 * expressed once per side (ADR-0047 rule 1); the panel is online on every
 * render, so it asks rather than computes. It replaces a hard-coded €20.00
 * cue that had nothing to do with the member's ceiling (#926).
 *
 * `null` means no ceiling is enforced for them, so no tab of theirs is amber.
 * `undefined` is the same answer for a different reason — a backend that
 * predates the field — and both degrade to *no cue* rather than a false one.
 * The parameter is required though nullable, so a call site that forgets
 * fails typecheck instead of silently never warning anybody.
 *
 * Amber begins **at** the band, and a positive balance is required for it:
 * `warn_threshold_percent` may be as low as 1, so a small ceiling rounds its
 * band down to zero, and a settled account in warning colour is bug #28.
 *
 * Note for git archaeology: a `getBalanceColor` existed here before and was
 * deleted in 26b4ea3 (#455) because it mapped the sign to a colour exactly
 * backwards. The name is the right one; this is the corrected polarity.
 */
export function getBalanceColor(
  balanceCents: number,
  warnAtCents: number | null | undefined
): string {
  if (balanceCents < 0) return theme.colors.semantic.success // credit
  if (warnAtCents != null && balanceCents > 0 && balanceCents >= warnAtCents) {
    return theme.colors.semantic.warning // inside their own warning band
  }
  return theme.colors.text.primary // settled, or an ordinary open tab
}

/**
 * Colour for a single *transaction amount* in a booking list — the dashboard's
 * recent bookings and the journal.
 *
 * Mirrors `transactionAmountColor()` in the terminal's `design_tokens.dart`:
 * only money in the member's favour is green. A charge is neutral — it is not
 * an error, so it is neither red nor amber. See ADR-0042.
 */
export function getTransactionAmountColor(amountCents: number): string {
  return amountCents < 0 ? theme.colors.semantic.success : theme.colors.text.primary
}
