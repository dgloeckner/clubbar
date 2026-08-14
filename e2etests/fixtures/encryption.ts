/**
 * The development IBAN encryption keypair (ADR-0036).
 *
 * Member IBANs are sealed under the club's public key and the server holds no
 * private key, so building a SEPA file means supplying the private half for the
 * length of one request (#393). `backend/db/seed.sql` installs the public half
 * as the ACTIVE dev key and points here for its counterpart.
 *
 * This key is published in the repository on purpose, and `IbanSealedBox`
 * refuses it outside development environments — a production install can never
 * run on it.
 */

import type { APIRequestContext, APIResponse } from '@playwright/test'
import { TEST_CREDENTIALS } from '../config/test-credentials'
import { generateTotp } from '../utils/totp'

/** The private half of `dev-key-2026`: 32 raw bytes, base64-encoded. */
export const DEV_PRIVATE_KEY = '9nj7F7WSwp21TkP4CO50/Wf33VxsQFsk4+MerTjzBYo='

/** SHA-256 of the seeded key's public half — how the server names that key. */
export const DEV_KEY_FINGERPRINT =
  '82ebd93f662cb26a5293137a00fbb6d0c239579c8df5855df1d00bcd1e092717'

/**
 * The second published dev keypair: what the rotation spec rotates *onto*
 * (#394).
 *
 * It has to be a fixed, committed keypair rather than one generated per run.
 * Rotation changes which key the whole installation is sealing under, and the
 * private half is the only way back to those IBANs — one invented inside a test
 * process would vanish with it, leaving a database nothing could read. Like the
 * first dev key, `IbanSealedBox` refuses it outside development.
 */
export const ROTATION_KEY_IDENTIFIER = 'dev-key-rotated'
export const ROTATION_PUBLIC_KEY = 'UV8PTrU0R4mA1zIBgrTJQnuFHz8ILPsx4YuEuelS0EA='
export const ROTATION_PRIVATE_KEY = 'Upb/frkw/rsuH0Cu4TaPzMklAwDT0wBManpSsreHEX4='
export const ROTATION_KEY_FINGERPRINT =
  '3416befa56777c5e9f72b3e36f69363dd021d8a9ae576d9d76538ba912380dbf'

/** Every keypair this repository publishes, by the fingerprint of its public half. */
const KNOWN_PRIVATE_KEYS: Record<string, string> = {
  [DEV_KEY_FINGERPRINT]: DEV_PRIVATE_KEY,
  [ROTATION_KEY_FINGERPRINT]: ROTATION_PRIVATE_KEY,
}

export interface EncryptionKeyRow {
  id: string
  key_identifier: string
  fingerprint_sha256: string
  status: string
  sealed_record_count: number | null
}

export async function listEncryptionKeys(request: APIRequestContext): Promise<EncryptionKeyRow[]> {
  const response = await request.get('/api/admin/encryption-keys')
  if (!response.ok()) {
    throw new Error(`listing encryption keys failed with ${response.status()}`)
  }

  return (await response.json()).keys as EncryptionKeyRow[]
}

/** Sessionless request contexts get these back rather than an exception. */
const NO_SESSION_STATUSES = [301, 302, 401, 403]

export async function activeEncryptionKey(request: APIRequestContext): Promise<EncryptionKeyRow> {
  const active = (await listEncryptionKeys(request)).find((key) => key.status === 'active')
  if (!active) {
    throw new Error('no active encryption key — the dev seed installs one')
  }

  return active
}

/**
 * The private key that opens what this installation is currently sealing with.
 *
 * Which key is active is not a constant: the rotation spec moves the whole
 * installation onto the second dev keypair, and every export after that needs
 * the other half of the safe. Asking the server which key is in force keeps the
 * export specs correct on either side of that.
 */
export async function activePrivateKey(request: APIRequestContext): Promise<string> {
  // The specs that prove the session gate deliberately hand in a context with
  // no session, and cannot read the listing. They are not going to reach the
  // decryption either, so any syntactically valid key does — asking which one
  // is in force would only turn a 401 assertion into a fixture crash.
  const probe = await request.get('/api/admin/encryption-keys')
  if (NO_SESSION_STATUSES.includes(probe.status())) {
    return DEV_PRIVATE_KEY
  }

  const active = await activeEncryptionKey(request)
  const privateKey = KNOWN_PRIVATE_KEYS[active.fingerprint_sha256]

  if (!privateKey) {
    throw new Error(
      `the active key "${active.key_identifier}" is not one this repository publishes a private half for`,
    )
  }

  return privateKey
}

/**
 * A syntactically valid key that belongs to a different keypair — what an admin
 * who grabbed the wrong sheet out of the safe would send. The server must
 * refuse it by public-key comparison, never by trying to decrypt with it.
 */
export const WRONG_PRIVATE_KEY = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8='

export interface SepaExportOptions {
  /** Defaults to whichever published dev key is currently active. */
  privateKey?: string | null
  /** Defaults to the seeded admin password; pass null to omit it entirely. */
  password?: string | null
  /**
   * Defaults to a fresh code for the seeded admin, who has 2FA enrolled — the
   * step-up asks for one whenever the caller does. Pass null to omit it.
   */
  totpCode?: string | null
}

/**
 * POST the SEPA export the way the admin panel does: the private key plus a
 * fresh step-up credential in one JSON body.
 */
export async function exportSepaXml(
  request: APIRequestContext,
  settlementId: string,
  options: SepaExportOptions = {},
): Promise<APIResponse> {
  const {
    // Resolved from the server rather than hardcoded: after a rotation the
    // installation is sealing under the other published keypair, and this one
    // request has to bring the half that matches.
    privateKey = await activePrivateKey(request),
    password = TEST_CREDENTIALS.admin.password,
    totpCode = generateTotp(TEST_CREDENTIALS.totp.adminSecret),
  } = options

  const data: Record<string, string> = {}
  if (privateKey !== null) data.private_key = privateKey
  if (password !== null) data.current_password = password
  if (totpCode !== null) data.totp_code = totpCode

  return request.post(`/api/admin/settlements/${settlementId}/export/sepa-xml`, { data })
}
