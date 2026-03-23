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
