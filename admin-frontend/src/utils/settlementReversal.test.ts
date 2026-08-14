import { describe, it, expect } from 'vitest'
import {
  allMembersReversed,
  settlementRowUndoAction,
  type SettlementReversalTarget,
} from './settlementReversal'

/**
 * The seven settlement row shapes the backend's `ReversalGateTest` pins, as the
 * API renders them — the pair of flags each shape arrives with.
 *
 * These are transcribed from the gate rather than computed, on purpose: the
 * point of the invariant below is that the row reads the backend's answer, so a
 * fixture that re-derived it would test nothing.
 */
const LIVE_ROW_SHAPES: Record<string, SettlementReversalTarget> = {
  'draft direct debit': { is_cancellable: true, is_reversible: false },
  'exported direct debit': {
    is_cancellable: true,
    is_reversible: false,
    exported_at: '2026-08-07T09:00:00Z',
  },
  'submitted direct debit': {
    is_cancellable: false,
    cancellation_blocked_reason: 'This settlement was submitted to the bank. Reverse it instead.',
    is_reversible: true,
  },
  'overdue direct debit': { is_cancellable: false, is_reversible: true },
  'bank transfer': { is_cancellable: false, is_reversible: true },
  'write off': { is_cancellable: true, is_reversible: false },
  'row predating the method column': { is_cancellable: true, is_reversible: false },
}

describe('settlementRowUndoAction', () => {
  /**
   * The invariant #81 turns on, and the one case that must not be skipped: the
   * row's single slot always offers the operation that is actually available.
   * A shape offering both would give the treasurer a choice the backend does
   * not have; a shape offering neither is the "door that refuses to open".
   */
  it('offers exactly one of cancel and reverse for every live settlement', () => {
    for (const [label, settlement] of Object.entries(LIVE_ROW_SHAPES)) {
      const action = settlementRowUndoAction(settlement)

      expect(action, `${label}: the row must offer an undo`).not.toBe('none')
      expect(action, `${label}: the slot follows the gate the backend opened`).toBe(
        settlement.is_reversible ? 'reverse' : 'cancel'
      )
    }
  })

  it('offers reversal once the run has moved money', () => {
    expect(settlementRowUndoAction({ is_cancellable: false, is_reversible: true })).toBe('reverse')
  })

  it('offers cancellation while the run has not', () => {
    expect(settlementRowUndoAction({ is_cancellable: true, is_reversible: false })).toBe('cancel')
  })

  it('offers nothing on a cancelled settlement — it collected nothing to return', () => {
    expect(
      settlementRowUndoAction({ is_cancelled: true, is_cancellable: false, is_reversible: false })
    ).toBe('none')
  })

  it('still offers cancel for a payload carrying neither flag', () => {
    // An older response is not a refusal. The dialog it opens states the
    // backend's own reason if the gate turns out to be shut (#127).
    expect(settlementRowUndoAction({ created_at: '2026-08-09T10:00:00Z' })).toBe('cancel')
  })

  it('prefers the backend answer over a stale cancellation flag', () => {
    // Both true cannot happen upstream; if it ever did, reversal is the safe
    // reading — offering cancellation on a run the bank has collected is the
    // double debit itself.
    expect(settlementRowUndoAction({ is_cancellable: true, is_reversible: true })).toBe('reverse')
  })
})

describe('allMembersReversed', () => {
  it('is true once every member of the run has come back', () => {
    expect(allMembersReversed({ member_count: 3, reversed_member_count: 3 })).toBe(true)
  })

  it('is false while some members are still settled', () => {
    expect(allMembersReversed({ member_count: 40, reversed_member_count: 1 })).toBe(false)
  })

  it('is false for a run with no reversals at all', () => {
    expect(allMembersReversed({ member_count: 40 })).toBe(false)
  })

  it('is false when the row carries no member count to compare against', () => {
    expect(allMembersReversed({ reversed_member_count: 2 })).toBe(false)
  })
})
