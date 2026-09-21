/**
 * Reading a terminal's dispenser report for the panel (ADR-0057, #954).
 *
 * The two fields the Terminals list carries — `dispenser_status` and
 * `dispenser_status_at` — have to answer four different questions, and three
 * of the four are easy to collapse into one another:
 *
 * - **unknown** — `dispenser_status` is null. Nothing has ever been reported:
 *   a terminal older than the route, a build that never speaks to a dispenser,
 *   or a document this backend refused. It is *not* "no dispenser".
 * - **none** — `configured: false`. That is a report, and it is the one that
 *   lets the panel say *no dispenser* rather than *unknown*.
 * - **available** / **unavailable** — the backend's own verdict. It is never
 *   recomputed here: `available` and `unavailable_reason` are derived
 *   server-side precisely so the kiosk and the panel cannot describe one
 *   machine differently, and a second implementation in TypeScript would be a
 *   second thing to get wrong.
 *
 * Two further distinctions the ADR insists on and this module keeps:
 *
 * - **Availability is not "needs a human".** `state != fault` is *can it serve
 *   a token*; `fault != none` is *does somebody have to walk over*. A
 *   controller that crashed and came back reports `idle` / `none` and must not
 *   look broken — {@link dispenserNeedsAttention} is what the detail view asks,
 *   and it reads `fault`, never `state`.
 * - **A protocol mismatch is not a device fault.** Nothing is wrong at the
 *   machine and the errand is a deployment one, so it carries its own remedy
 *   and its own (non-danger) colour. Folding it into "offline" is epic
 *   finding 13.
 *
 * Nothing here formats. The age is returned as a unit and a number so the
 * component can put it through i18n, and so the boundaries can be tested
 * without a locale.
 */

import type {
  TerminalDispenserStatus,
  TerminalDispenserStatusUnavailableReason,
} from '../api/generated/model'

/** What the cell renders. Four states, all distinguishable from the two fields. */
export type DispenserDisplayState = 'unknown' | 'none' | 'available' | 'unavailable'

export interface DispenserDisplay {
  state: DispenserDisplayState
  /** Only set when `state` is `unavailable`; the server's reason, never guessed. */
  reason: TerminalDispenserStatusUnavailableReason | null
  /** The Azkoyen code behind a `hopper_error`, so the copy can name it. Null otherwise. */
  faultCode: number | null
}

/**
 * Which of the four states this report is in.
 *
 * A configured dispenser that is not `available` always gets a reason:
 * `unspecified_fault` is the floor, so a document whose verdict cannot be read
 * can never be rendered as working.
 */
export function dispenserDisplay(
  status: TerminalDispenserStatus | null | undefined,
): DispenserDisplay {
  if (status === null || status === undefined) {
    return { state: 'unknown', reason: null, faultCode: null }
  }

  if (status.configured === false) {
    return { state: 'none', reason: null, faultCode: null }
  }

  if (status.available === true) {
    return { state: 'available', reason: null, faultCode: null }
  }

  const reason = status.unavailable_reason ?? 'unspecified_fault'

  return {
    state: 'unavailable',
    reason,
    faultCode: reason === 'hopper_error' && typeof status.fault_code === 'number' ? status.fault_code : null,
  }
}

/**
 * Does somebody have to walk to the machine?
 *
 * The device-level fault, and nothing else. An unreachable dispenser and a
 * protocol mismatch are both unavailable and neither is an errand at the
 * hopper; a crash the controller recovered from is neither.
 */
export function dispenserNeedsAttention(
  status: TerminalDispenserStatus | null | undefined,
): boolean {
  return status?.fault !== undefined && status.fault !== null && status.fault !== 'none'
}

export type DispenserAgeUnit = 'now' | 'minutes' | 'hours' | 'days'

export interface DispenserAge {
  unit: DispenserAgeUnit
  value: number
}

/**
 * How old the report is, as a unit and a count.
 *
 * ADR-0057's mitigation for its own worst consequence: the backend cannot poll
 * the dispenser, so a terminal that is off reports nothing and a dropped report
 * freezes the cell silently. A status with no age beside it is a claim nobody
 * can check, so the cell never renders one without the other.
 *
 * A stamp in the future (a clock skew between the reader and the server) reads
 * as "just now" rather than as a negative age.
 */
export function dispenserAge(
  at: string | null | undefined,
  now: Date = new Date(),
): DispenserAge | null {
  if (!at) return null

  const then = new Date(at)
  if (Number.isNaN(then.getTime())) return null

  return durationParts(Math.floor((now.getTime() - then.getTime()) / 1000))
}

/**
 * A span of seconds as the coarsest unit that still says something.
 *
 * Shared by the report's age and the controller's uptime, which is half of the
 * reboot oracle: `reset_reason` says *why* it last came up and `uptime_s` says
 * *when*, and the two are read by **comparing against the previous value**,
 * never by parsing the reason string.
 */
export function durationParts(seconds: number): DispenserAge {
  if (!Number.isFinite(seconds) || seconds < 60) return { unit: 'now', value: 0 }
  if (seconds < 3600) return { unit: 'minutes', value: Math.floor(seconds / 60) }
  if (seconds < 86400) return { unit: 'hours', value: Math.floor(seconds / 3600) }
  return { unit: 'days', value: Math.floor(seconds / 86400) }
}

/**
 * Whether a counter the firmware may simply not publish is there at all.
 *
 * O2's rule: when `contact != reported` the fields are **absent, not zero** —
 * firmware, uptime, RSSI, reset reason and the whole `lifetime` object — and
 * `filtered_pulses` is absent against the mock even on a healthy report.
 * Rendering a missing counter as `0` invents a measurement: "0 jams" and "we
 * never heard" are not the same claim.
 */
export function hasCounter(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value)
}
