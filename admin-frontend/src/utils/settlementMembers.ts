/**
 * What a settlement collected, per member (epic #433 §7).
 *
 * The single-settlement read returns `items[]` — one row per settled
 * transaction — and `reversals[]` together, which is exactly enough to answer
 * the question a treasurer expanding *"1 von 40 zurückgebucht"* is asking:
 * *which one*, and out of what.
 *
 * The reversals alone would not do it. A reversal shown without the other
 * thirty-nine members loses the denominator, so the breakdown is built from the
 * items and the reversal is attached to the member it names.
 *
 * Item rows survive a reversal (ruling #142 §3), so a member's amount here is
 * what the run originally collected from them — the same figure the reversal
 * freed, and the figure the member checklist has to show before one is made.
 */

import type { SettlementItem, SettlementReversal } from '../api/generated'

export interface SettlementMemberLine {
  memberId: string
  memberName: string | null
  /** This member's total in this run, reversed or not. */
  amountCents: number
  transactionCount: number
  /** The reversal naming this member, when one exists. */
  reversal: SettlementReversal | null
}

export function settlementMemberLines(
  items: SettlementItem[] | undefined,
  reversals: SettlementReversal[] | undefined
): SettlementMemberLine[] {
  const reversalByMember = new Map<string, SettlementReversal>()
  for (const reversal of reversals ?? []) {
    if (reversal.member_id) reversalByMember.set(reversal.member_id, reversal)
  }

  const lines = new Map<string, SettlementMemberLine>()
  for (const item of items ?? []) {
    const memberId = item.member_id
    if (!memberId) continue

    const line = lines.get(memberId) ?? {
      memberId,
      memberName: item.member_name ?? null,
      amountCents: 0,
      transactionCount: 0,
      reversal: reversalByMember.get(memberId) ?? null,
    }

    line.amountCents += item.amount_cents ?? 0
    line.transactionCount += 1
    lines.set(memberId, line)
  }

  return [...lines.values()].sort((a, b) =>
    (a.memberName ?? '').localeCompare(b.memberName ?? '')
  )
}
