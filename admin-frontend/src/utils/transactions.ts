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
 * Open tabs above this amount are shown in the warning colour.
 *
 * Mirrors `AppMoney.warnAboveCents` in
 * `terminal-frontend/lib/utils/design_tokens.dart`. Deliberately *not* the
 * credit limit: this is a reading cue ("that tab is getting substantial"),
 * while the credit limit decides what a member may still buy and is surfaced
 * by the dashboard's near-limit widget.
 */
export const MONEY_WARN_ABOVE_CENTS = 2000 // €20.00

/**
 * Colour for a *balance* (the Deckel) — members table and member cards.
 *
 * Mirrors `balanceColor()` in the terminal's `design_tokens.dart`. See
 * ADR-0042 for the cross-app rule and the sign convention it rests on.
 *
 * Note for git archaeology: a `getBalanceColor` existed here before and was
 * deleted in 26b4ea3 (#455) because it mapped the sign to a colour exactly
 * backwards. The name is the right one; this is the corrected polarity.
 */
export function getBalanceColor(balanceCents: number): string {
  if (balanceCents < 0) return theme.colors.semantic.success // credit
  if (balanceCents > MONEY_WARN_ABOVE_CENTS) return theme.colors.semantic.warning
  return theme.colors.text.primary // settled account or small open tab
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
