import { describe, it, expect } from 'vitest'
import { settlementMemberLines } from './settlementMembers'
import type {
  QueuedMail,
  SettlementAnnouncement,
  SettlementItem,
  SettlementReversal,
} from '../api/generated'

const item = (memberId: string, amountCents: number, memberName: string): SettlementItem => ({
  member_id: memberId,
  member_name: memberName,
  amount_cents: amountCents,
})

const reversal = (memberId: string, amountCents: number): SettlementReversal => ({
  id: `rev-${memberId}`,
  member_id: memberId,
  amount_cents: amountCents,
  reason: 'bank_return',
  bank_reference: 'RET-1',
  created_by_admin_name: 'The Treasurer',
  created_at: '2026-08-21T09:30:00Z',
})

const queued = (memberId: string, status: QueuedMail['status']): QueuedMail =>
  ({ id: `mail-${memberId}`, member_id: memberId, kind: 'sepa_prenotification', status }) as QueuedMail

const announcement = (memberId: string, sentAt: string): SettlementAnnouncement => ({
  member_id: memberId,
  kind: 'sepa_prenotification',
  sent_at: sentAt,
})

describe('settlementMemberLines', () => {
  it('sums a member’s transactions into one line', () => {
    const [line] = settlementMemberLines(
      [item('alice', 1000, 'Alice Member'), item('alice', 550, 'Alice Member')],
      []
    )

    expect(line.memberId).toBe('alice')
    expect(line.amountCents).toBe(1550)
    expect(line.transactionCount).toBe(2)
  })

  it('attaches a reversal to the member it names, and to nobody else', () => {
    const lines = settlementMemberLines(
      [item('alice', 1500, 'Alice Member'), item('bob', 2500, 'Bob Member')],
      [reversal('alice', 1500)]
    )

    const alice = lines.find((l) => l.memberId === 'alice')
    const bob = lines.find((l) => l.memberId === 'bob')

    expect(alice?.reversal?.bank_reference).toBe('RET-1')
    expect(bob?.reversal).toBeNull()
  })

  it('keeps the members who were not reversed', () => {
    // The denominator is the point: someone expanding "1 von 2 zurückgebucht"
    // is asking *which one*, and a reversal shown alone loses the other.
    const lines = settlementMemberLines(
      [item('alice', 1500, 'Alice Member'), item('bob', 2500, 'Bob Member')],
      [reversal('alice', 1500)]
    )

    expect(lines).toHaveLength(2)
  })

  it('reports the amount the run collected, not what survived the reversal', () => {
    // Item rows survive a reversal (ruling #142 §3), so this is the figure the
    // reversal freed — and the figure the member checklist shows before one.
    const [line] = settlementMemberLines([item('alice', 1500, 'Alice Member')], [reversal('alice', 1500)])

    expect(line.amountCents).toBe(1500)
  })

  it('orders members by name so the same run reads the same way twice', () => {
    const lines = settlementMemberLines(
      [item('zoe', 100, 'Zoe Member'), item('alice', 100, 'Alice Member'), item('mo', 100, 'Mo Member')],
      []
    )

    expect(lines.map((l) => l.memberName)).toEqual(['Alice Member', 'Mo Member', 'Zoe Member'])
  })

  it('skips an item carrying no member rather than inventing a line for it', () => {
    const lines = settlementMemberLines([{ amount_cents: 500 }, item('alice', 100, 'Alice Member')], [])

    expect(lines).toHaveLength(1)
    expect(lines[0].memberId).toBe('alice')
  })

  it('ignores a reversal that names no member', () => {
    const [line] = settlementMemberLines([item('alice', 100, 'Alice Member')], [{ id: 'rev-orphan' }])

    expect(line.reversal).toBeNull()
  })

  it('returns nothing for a settlement with no items', () => {
    expect(settlementMemberLines([], [])).toEqual([])
    expect(settlementMemberLines(undefined, undefined)).toEqual([])
  })

  it('survives an item with no member name', () => {
    const [line] = settlementMemberLines([{ member_id: 'alice', amount_cents: 100 }], [])

    expect(line.memberName).toBeNull()
    expect(line.amountCents).toBe(100)
  })

  it('counts an item with no amount as a transaction worth nothing', () => {
    const [line] = settlementMemberLines([{ member_id: 'alice' }], [])

    expect(line.amountCents).toBe(0)
    expect(line.transactionCount).toBe(1)
  })

  /* ────────────── the record that outlives the queue (#408) ────────────── */

  it('reads the durable announcement when the queue row has been pruned', () => {
    // Ninety days after delivery the queue row is deleted, address and all. If
    // the breakdown read only `notification`, every collection older than a
    // quarter would render as "no address" — the one label that means somebody
    // has to be rung up.
    const [line] = settlementMemberLines(
      [item('alice', 100, 'Alice Member')],
      [],
      [],
      [announcement('alice', '2026-05-01 09:00:00')]
    )

    expect(line.notification).toBeNull()
    expect(line.announcedAt).toBe('2026-05-01 09:00:00')
  })

  it('leaves a member nobody could be told about with neither', () => {
    const [line] = settlementMemberLines([item('alice', 100, 'Alice Member')], [], [], [])

    expect(line.notification).toBeNull()
    expect(line.announcedAt).toBeNull()
  })

  it('keeps both while both exist, so the richer answer stays available', () => {
    const [line] = settlementMemberLines(
      [item('alice', 100, 'Alice Member')],
      [],
      [queued('alice', 'failed')],
      [announcement('alice', '2026-05-01 09:00:00')]
    )

    expect(line.notification?.status).toBe('failed')
    expect(line.announcedAt).toBe('2026-05-01 09:00:00')
  })

  it('ignores a durable record for a different kind of message', () => {
    // The breakdown answers "was this member told about the collection". A
    // notice retracting it is a different message with a different fate.
    const [line] = settlementMemberLines(
      [item('alice', 100, 'Alice Member')],
      [],
      [],
      [{ member_id: 'alice', kind: 'cancellation_notice', sent_at: '2026-05-02 09:00:00' }]
    )

    expect(line.announcedAt).toBeNull()
  })
})
