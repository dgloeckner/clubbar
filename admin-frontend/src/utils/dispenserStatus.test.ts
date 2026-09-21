/**
 * The four display states, and the two distinctions that are easy to lose.
 *
 * These are unit tests rather than E2E because the interesting input is a
 * document the backend derived, and driving a real dispenser into a protocol
 * mismatch or a hopper error through the UI would take a firmware release and
 * a wedged coin.
 */

import { describe, expect, it } from 'vitest'

import {
  dispenserAge,
  durationParts,
  dispenserDisplay,
  dispenserFill,
  dispenserNeedsAttention,
  hasCounter,
} from './dispenserStatus'

const healthy: Record<string, unknown> = {
  configured: true,
  contact: 'reported',
  state: 'idle',
  fault: 'none',
  fault_code: 0,
  available: true,
  unavailable_reason: null,
}

describe('dispenserDisplay', () => {
  it('reads a missing report as unknown, never as "no dispenser"', () => {
    // Null means nobody ever told us. `configured: false` means somebody did,
    // and said there is nothing attached — a different sentence entirely.
    expect(dispenserDisplay(null)).toEqual({ state: 'unknown', reason: null, faultCode: null })
    expect(dispenserDisplay(undefined)).toEqual({ state: 'unknown', reason: null, faultCode: null })
  })

  it('reads `configured: false` as a report that there is no dispenser', () => {
    expect(dispenserDisplay({ configured: false, available: false } as never)).toEqual({
      state: 'none',
      reason: null,
      faultCode: null,
    })
  })

  it('takes the backend at its word when the dispenser can serve', () => {
    expect(dispenserDisplay(healthy)).toEqual({ state: 'available', reason: null, faultCode: null })
  })

  it('carries the named reason for every way of being unavailable', () => {
    for (const reason of ['offline', 'protocol_mismatch', 'jam', 'unspecified_fault'] as const) {
      expect(dispenserDisplay({ ...healthy, available: false, unavailable_reason: reason } as never)).toEqual({
        state: 'unavailable',
        reason,
        faultCode: null,
      })
    }
  })

  it('keeps the Azkoyen code beside a hopper error', () => {
    // "Hopper error" alone is not something an admin can repeat over the phone.
    expect(
      dispenserDisplay({
        ...healthy,
        available: false,
        unavailable_reason: 'hopper_error',
        fault: 'hopper_error',
        fault_code: 5,
      } as never),
    ).toEqual({ state: 'unavailable', reason: 'hopper_error', faultCode: 5 })
  })

  it('never renders an unavailable dispenser as working when the reason is missing', () => {
    // A document whose verdict cannot be read must fall to the floor, not to
    // green.
    expect(dispenserDisplay({ configured: true, available: false } as never)).toEqual({
      state: 'unavailable',
      reason: 'unspecified_fault',
      faultCode: null,
    })
  })

  it('does not recompute the verdict from state and fault', () => {
    // The server derives `available`; a second implementation here would be a
    // second thing to get wrong. A report that says `state: fault` and
    // `available: true` is a backend bug, and the panel must surface the
    // backend's answer rather than quietly disagree with the kiosk.
    expect(dispenserDisplay({ ...healthy, state: 'fault', fault: 'jam' } as never).state).toBe('available')
  })
})

describe('dispenserNeedsAttention', () => {
  it('is false for a controller that crashed and came back', () => {
    // `idle` / `none` with an error transaction behind it: available, and
    // nobody has to walk anywhere.
    expect(dispenserNeedsAttention({ ...healthy, state: 'idle', fault: 'none' } as never)).toBe(false)
  })

  it('is false for the two unavailable states that are not device faults', () => {
    // Nothing is wrong at the hopper in either case: one is a power or WiFi
    // errand, the other a deployment one.
    expect(
      dispenserNeedsAttention({ contact: 'unreachable', fault: 'none', available: false } as never),
    ).toBe(false)
    expect(
      dispenserNeedsAttention({ contact: 'protocol_mismatch', fault: 'none', available: false } as never),
    ).toBe(false)
  })

  it('is true for a named device fault', () => {
    expect(dispenserNeedsAttention({ ...healthy, fault: 'jam' } as never)).toBe(true)
    expect(dispenserNeedsAttention({ ...healthy, fault: 'hopper_error' } as never)).toBe(true)
  })

  it('is false when nothing was reported at all', () => {
    expect(dispenserNeedsAttention(null)).toBe(false)
  })
})

describe('dispenserAge', () => {
  const now = new Date('2026-09-21T12:00:00Z')

  it('has no age for a report that never arrived', () => {
    expect(dispenserAge(null, now)).toBeNull()
    expect(dispenserAge(undefined, now)).toBeNull()
    expect(dispenserAge('not a date', now)).toBeNull()
  })

  it('walks up the units at the boundaries', () => {
    expect(dispenserAge('2026-09-21T11:59:30Z', now)).toEqual({ unit: 'now', value: 0 })
    expect(dispenserAge('2026-09-21T11:59:00Z', now)).toEqual({ unit: 'minutes', value: 1 })
    expect(dispenserAge('2026-09-21T11:01:00Z', now)).toEqual({ unit: 'minutes', value: 59 })
    expect(dispenserAge('2026-09-21T11:00:00Z', now)).toEqual({ unit: 'hours', value: 1 })
    expect(dispenserAge('2026-09-20T12:00:01Z', now)).toEqual({ unit: 'hours', value: 23 })
    expect(dispenserAge('2026-09-20T12:00:00Z', now)).toEqual({ unit: 'days', value: 1 })
    expect(dispenserAge('2026-09-01T12:00:00Z', now)).toEqual({ unit: 'days', value: 20 })
  })

  it('reads a stamp from the future as "just now"', () => {
    // A reader's clock a few seconds ahead of the server must not produce
    // "vor -1 Min.".
    expect(dispenserAge('2026-09-21T12:05:00Z', now)).toEqual({ unit: 'now', value: 0 })
  })
})

describe('durationParts', () => {
  it('picks the coarsest unit that still says something', () => {
    expect(durationParts(0)).toEqual({ unit: 'now', value: 0 })
    expect(durationParts(59)).toEqual({ unit: 'now', value: 0 })
    expect(durationParts(60)).toEqual({ unit: 'minutes', value: 1 })
    expect(durationParts(3600)).toEqual({ unit: 'hours', value: 1 })
    // The healthy fixture's uptime: one day, not 86 400 of anything.
    expect(durationParts(86400)).toEqual({ unit: 'days', value: 1 })
  })

  it('does not turn a nonsensical uptime into a number', () => {
    expect(durationParts(Number.NaN)).toEqual({ unit: 'now', value: 0 })
    expect(durationParts(-5)).toEqual({ unit: 'now', value: 0 })
  })
})

describe('hasCounter', () => {
  it('separates a counter that is absent from one that is zero', () => {
    // O2's rule: when contact != reported the counters are absent, not zero.
    // "0 jams" and "we never heard" are different claims.
    expect(hasCounter(0)).toBe(true)
    expect(hasCounter(1409)).toBe(true)
    expect(hasCounter(undefined)).toBe(false)
    expect(hasCounter(null)).toBe(false)
    expect(hasCounter('3')).toBe(false)
    expect(hasCounter(Number.NaN)).toBe(false)
  })
})

/**
 * The hopper estimate (#955, ADR-0058) — arithmetic, not a sensor, and the
 * reading has to keep saying so. The machine has no empty switch; this is a
 * counted refill minus what has been billed since.
 */
describe('dispenserFill', () => {
  const fill = (overrides: Record<string, unknown> = {}) =>
    dispenserFill({ refilled_at: '2026-09-20T18:00:00Z', refill_tokens: 400, sold_since: 137, estimated_left: 263, low_threshold: 20, ...overrides } as never)

  it('reads a comfortable hopper as ok', () => {
    expect(fill()).toEqual({ state: 'ok', estimatedLeft: 263, threshold: 20 })
  })

  it('warns at the threshold, not only below it', () => {
    expect(fill({ estimated_left: 20 }).state).toBe('low')
    expect(fill({ estimated_left: 21 }).state).toBe('ok')
  })

  it('reads a used-up load as exhausted rather than as a number', () => {
    // The backend floors the estimate at zero: a negative number would be
    // false precision about a drift nobody measured.
    expect(fill({ estimated_left: 0 })).toEqual({ state: 'exhausted', estimatedLeft: 0, threshold: 20 })
  })

  /**
   * The `hasCounter` rule, applied to the estimate: an absent number is not a
   * zero. "No refill recorded" and "the hopper is empty" are opposite errands,
   * and `undefined ?? 0` would turn the first into the second.
   */
  it('makes no claim when there is nothing to estimate from', () => {
    expect(dispenserFill(null)).toEqual({ state: 'unknown', estimatedLeft: null, threshold: null })
    expect(dispenserFill(undefined)).toEqual({ state: 'unknown', estimatedLeft: null, threshold: null })
    expect(fill({ estimated_left: null })).toEqual({ state: 'unknown', estimatedLeft: null, threshold: 20 })
    expect(fill({ estimated_left: undefined })).toEqual({ state: 'unknown', estimatedLeft: null, threshold: 20 })
  })

  /** A threshold of zero warns only once the estimate is used up. */
  it('honours a threshold of zero', () => {
    expect(fill({ low_threshold: 0, estimated_left: 1 }).state).toBe('ok')
    expect(fill({ low_threshold: 0, estimated_left: 0 }).state).toBe('exhausted')
  })
})

describe('dispenserDisplay with an estimate beside the report', () => {
  const healthyStatus = { configured: true, contact: 'reported', state: 'idle', fault: 'none', available: true, unavailable_reason: null }
  const low = { refilled_at: '2026-09-20T18:00:00Z', refill_tokens: 400, sold_since: 390, estimated_left: 10, low_threshold: 20 }

  it('adds a fifth state between ready and unavailable', () => {
    expect(dispenserDisplay(healthyStatus as never, low as never).state).toBe('low')
  })

  /**
   * `available` is the backend's verdict precisely so that the kiosk and the
   * panel cannot describe one machine differently. An estimate is a guess
   * about a hopper somebody may have topped up without saying so, and it is
   * never evidence against a machine that is serving.
   */
  it('never unsets availability, in either direction', () => {
    const jammed = { ...healthyStatus, state: 'fault', fault: 'jam', available: false, unavailable_reason: 'jam' }
    const full = { ...low, sold_since: 0, estimated_left: 400 }

    expect(dispenserDisplay(jammed as never, full as never).state).toBe('unavailable')
    expect(dispenserDisplay(jammed as never, low as never).reason).toBe('jam')
  })

  it('leaves the four report-only states exactly as they were', () => {
    expect(dispenserDisplay(null, low as never).state).toBe('unknown')
    expect(dispenserDisplay({ configured: false } as never, low as never).state).toBe('none')
    expect(dispenserDisplay(healthyStatus as never).state).toBe('available')
  })
})
