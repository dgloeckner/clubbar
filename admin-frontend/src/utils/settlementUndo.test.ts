import { describe, it, expect } from 'vitest'
import { describeUndo, undoDisplayDate } from './settlementUndo'

describe('describeUndo', () => {
  it('lets an ordinary settlement through with nothing to acknowledge', () => {
    const decision = describeUndo({ is_cancellable: true, exported_at: null })

    expect(decision.blocked).toBe(false)
    expect(decision.blockedReason).toBeNull()
    expect(decision.needsExportAcknowledgement).toBe(false)
  })

  it('blocks a settlement the backend refuses, quoting its reason', () => {
    const decision = describeUndo({
      is_cancellable: false,
      cancellation_blocked_reason: 'This settlement was submitted to the bank on 2026-08-01, so the money has moved. Reverse it instead.',
    })

    expect(decision.blocked).toBe(true)
    expect(decision.blockedReason).toContain('Reverse it instead.')
    // Nothing to acknowledge — there is no confirm button to unlock.
    expect(decision.needsExportAcknowledgement).toBe(false)
  })

  it('blocks an already-cancelled settlement even when the flag says otherwise', () => {
    expect(describeUndo({ is_cancelled: true, is_cancellable: true }).blocked).toBe(true)
  })

  it('treats an absent is_cancellable as "not blocked", not as a refusal', () => {
    // Rows read through an older payload carry no flag. Blocking on `!flag`
    // would disable Undo for every settlement such a response describes.
    const decision = describeUndo({ created_at: '2026-08-09T10:00:00Z' })

    expect(decision.blocked).toBe(false)
    expect(decision.blockedReason).toBeNull()
  })

  it('demands an acknowledgement once a SEPA file has been generated', () => {
    const decision = describeUndo({ is_cancellable: true, exported_at: '2026-08-09T10:00:00Z' })

    expect(decision.blocked).toBe(false)
    expect(decision.needsExportAcknowledgement).toBe(true)
  })

  it('does not demand an acknowledgement it cannot act on when the gate is shut', () => {
    const decision = describeUndo({
      is_cancellable: false,
      cancellation_blocked_reason: 'Reverse it instead.',
      exported_at: '2026-08-09T10:00:00Z',
    })

    expect(decision.blocked).toBe(true)
    expect(decision.needsExportAcknowledgement).toBe(false)
  })

  it('drops a stale reason when the settlement is cancellable after all', () => {
    const decision = describeUndo({
      is_cancellable: true,
      cancellation_blocked_reason: 'stale',
    })

    expect(decision.blockedReason).toBeNull()
  })
})

describe('undoDisplayDate', () => {
  it('prefers the settlement date over the row creation timestamp', () => {
    expect(undoDisplayDate({ settlement_date: '2026-08-01', created_at: '2026-08-09T10:00:00Z' }))
      .toBe('2026-08-01')
  })

  it('falls back to created_at when the run carries no settlement date', () => {
    expect(undoDisplayDate({ created_at: '2026-08-09T10:00:00Z' })).toBe('2026-08-09T10:00:00Z')
  })

  it('returns an empty string rather than undefined when neither is present', () => {
    expect(undoDisplayDate({})).toBe('')
  })
})
