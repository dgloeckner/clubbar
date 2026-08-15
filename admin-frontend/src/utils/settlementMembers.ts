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

import type { QueuedMail, SettlementItem, SettlementReversal } from '../api/generated'

export interface SettlementMemberLine {
  memberId: string
  memberName: string | null
  /** This member's total in this run, reversed or not. */
  amountCents: number
  transactionCount: number
  /** The reversal naming this member, when one exists. */
  reversal: SettlementReversal | null
  /**
   * The announcement queued for this member, when there is one (#407).
   *
   * Absent for a member with no email address — no row is written for them at
   * all — which is why the breakdown says *"no address"* rather than leaving a
   * gap the reader has to interpret.
   *
   * The **announcement**, not the cancellation notice: the question the
   * breakdown answers is "was this member told about the collection", and a
   * notice retracting it is a different message with a different fate.
   */
  notification: QueuedMail | null
}

export function settlementMemberLines(
  items: SettlementItem[] | undefined,
  reversals: SettlementReversal[] | undefined,
  notifications?: QueuedMail[]
): SettlementMemberLine[] {
  const reversalByMember = new Map<string, SettlementReversal>()
  for (const reversal of reversals ?? []) {
    if (reversal.member_id) reversalByMember.set(reversal.member_id, reversal)
  }

  const announcementByMember = new Map<string, QueuedMail>()
  for (const message of notifications ?? []) {
    if (message.member_id && message.kind === 'sepa_prenotification') {
      announcementByMember.set(message.member_id, message)
    }
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
      notification: announcementByMember.get(memberId) ?? null,
    }

    line.amountCents += item.amount_cents ?? 0
    line.transactionCount += 1
    lines.set(memberId, line)
  }

  return [...lines.values()].sort((a, b) =>
    (a.memberName ?? '').localeCompare(b.memberName ?? '')
  )
}
