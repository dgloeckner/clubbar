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
