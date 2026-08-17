/**
 * TOTP (Time-based One-Time Password) Utilities for E2E Tests
 *
 * RFC 6238 compliant TOTP code generation using Node.js built-in crypto module.
 * No external packages required — keeps the test dependency footprint minimal.
 *
 * Usage:
 *   import { generateTotp } from '../utils/totp'
 *   const code = generateTotp('JBSWY3DPEHPK3PXP')         // current window
 *   const prev = generateTotp('JBSWY3DPEHPK3PXP', -30)    // previous 30-second window
 */

import { createHmac } from 'crypto'
import type { APIResponse } from '@playwright/test'

/**
 * Decode a base32-encoded string to a Buffer.
 *
 * Supports both uppercase and lowercase input; handles padding-free strings.
 * The base32 alphabet is A-Z (0-25) and 2-7 (26-31) per RFC 4648.
 */
export function base32ToBytes(base32: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const input = base32.toUpperCase().replace(/=+$/, '')

  let bits = 0
  let value = 0
  const bytes: number[] = []

  for (let i = 0; i < input.length; i++) {
    const charIndex = alphabet.indexOf(input[i])
    if (charIndex === -1) {
      throw new Error(`Invalid base32 character: '${input[i]}'`)
    }

    value = (value << 5) | charIndex
    bits += 5

    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff)
      bits -= 8
    }
  }

  return Buffer.from(bytes)
}

/**
 * Generate a 6-digit TOTP code for the given secret.
 *
 * Implements RFC 6238 (TOTP) over RFC 4226 (HOTP):
 * - HMAC-SHA1 with 30-second time steps
 * - Dynamic truncation to produce a 6-digit decimal code
 * - `timeOffsetSeconds` shifts the counter, enabling tests to generate codes
 *   for the previous or next window without changing system time
 *
 * @param secret           Base32-encoded TOTP secret (e.g. 'JBSWY3DPEHPK3PXP')
 * @param timeOffsetSeconds Optional offset in seconds (default 0 = current window)
 */
export function generateTotp(secret: string, timeOffsetSeconds = 0): string {
  const TIME_STEP = 30
  const DIGITS = 6

  // Counter = floor((now + offset) / 30)
  const nowSeconds = Math.floor(Date.now() / 1000) + timeOffsetSeconds
  const counter = Math.floor(nowSeconds / TIME_STEP)

  // Encode the 8-byte big-endian counter
  const counterBuffer = Buffer.alloc(8)
  const high = Math.floor(counter / 0x100000000)
  const low = counter >>> 0
  counterBuffer.writeUInt32BE(high, 0)
  counterBuffer.writeUInt32BE(low, 4)

  // HMAC-SHA1 of the counter with the decoded secret
  const keyBytes = base32ToBytes(secret)
  const hmac = createHmac('sha1', keyBytes).update(counterBuffer).digest()

  // Dynamic truncation (RFC 4226 §5.4)
  const offset = hmac[hmac.length - 1] & 0x0f
  const truncated =
    ((hmac[offset] & 0x7f) << 24) |
    ((hmac[offset + 1] & 0xff) << 16) |
    ((hmac[offset + 2] & 0xff) << 8) |
    (hmac[offset + 3] & 0xff)

  const otp = truncated % Math.pow(10, DIGITS)
  return otp.toString().padStart(DIGITS, '0')
}

/**
 * Submit a TOTP code, retrying with a freshly generated one if the server
 * rejects it — guards the shared seeded admin's secret (TEST_CREDENTIALS.totp.adminSecret)
 * against replay-collisions between concurrent test workers/files: two
 * `/api/auth/mfa` calls landing in the same 30-second window generate and
 * submit the *identical* code, which replay protection (#338) correctly
 * refuses on the second submission even though it is not a real replay
 * attack — just two unrelated logins racing the same clock window.
 *
 * `submit` must return the raw response so the caller can still inspect it
 * (cookies, JSON body, ...); only its status code drives the retry decision.
 * Waits past the next time-step boundary before retrying, since the code for
 * the current window will not change until then — plus random jitter, so that
 * several callers who collided in the same window (and would otherwise all
 * retry in lockstep into the *next* window together) spread out instead of
 * colliding again. Callers should raise the test timeout well past
 * `maxAttempts * 60s` to comfortably fit the worst case.
 */
export async function submitTotpWithRetry(
  secret: string,
  submit: (code: string) => Promise<APIResponse>,
  maxAttempts = 6,
): Promise<APIResponse> {
  let response: APIResponse
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    response = await submit(generateTotp(secret))
    if (response.status() !== 401 || attempt === maxAttempts) {
      return response
    }
    const msUntilNextStep = 30_000 - (Date.now() % 30_000)
    const jitter = Math.floor(Math.random() * 30_000)
    await new Promise((resolve) => setTimeout(resolve, msUntilNextStep + jitter + 250))
  }
  return response!
}

/**
 * Wait out the rest of the current 30-second TOTP step.
 *
 * `totp_last_timestep` replay protection (#338) is per admin row and rejects
 * any code whose step is not strictly later than the last one accepted for
 * that row — enrollment (`/auth/2fa/confirm`) and login (`/auth/mfa`) both
 * consume a step. A caller that enrolls an admin and then, moments later,
 * logs that same admin in for real again (a second live session, a
 * re-authentication after a password change, ...) is not racing another
 * caller's clock window the way `submitTotpWithRetry` guards against — it is
 * racing *itself*, in the same window it just consumed. That is a near-even
 * coin flip resolved by `submitTotpWithRetry` only after a wasted round trip,
 * a full step's wait and up to 30s of jitter meant for spreading out
 * unrelated concurrent callers, which does nothing useful when there is only
 * one caller. Awaiting this first makes the next `generateTotp()` land in a
 * fresh step on the first attempt instead.
 */
export async function waitForFreshTotpWindow(): Promise<void> {
  const msUntilNextStep = 30_000 - (Date.now() % 30_000)
  await new Promise((resolve) => setTimeout(resolve, msUntilNextStep + 250))
}
