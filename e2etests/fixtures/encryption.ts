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

/**
 * A syntactically valid key that belongs to a different keypair — what an admin
 * who grabbed the wrong sheet out of the safe would send. The server must
 * refuse it by public-key comparison, never by trying to decrypt with it.
 */
export const WRONG_PRIVATE_KEY = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8='

export interface SepaExportOptions {
  /** Defaults to the dev private key. */
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
    privateKey = DEV_PRIVATE_KEY,
    password = TEST_CREDENTIALS.admin.password,
    totpCode = generateTotp(TEST_CREDENTIALS.totp.adminSecret),
  } = options

  const data: Record<string, string> = {}
  if (privateKey !== null) data.private_key = privateKey
  if (password !== null) data.current_password = password
  if (totpCode !== null) data.totp_code = totpCode

  return request.post(`/api/admin/settlements/${settlementId}/export/sepa-xml`, { data })
}
