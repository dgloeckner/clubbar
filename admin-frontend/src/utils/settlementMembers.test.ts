import { describe, it, expect } from 'vitest'
import { settlementMemberLines } from './settlementMembers'
import type { SettlementItem, SettlementReversal } from '../api/generated'

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
})
