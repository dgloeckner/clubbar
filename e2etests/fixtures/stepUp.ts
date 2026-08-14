/**
 * The step-up credential every "prove it is really you" endpoint asks for
 * (#337, ADR-0036): the caller's own password, plus their own fresh TOTP code
 * when they have 2FA enrolled — which the seeded admin does.
 *
 * Gathered here rather than repeated per spec because the set of endpoints
 * behind a step-up keeps growing (2FA reset, password reset, encryption keys,
 * SEPA export, and now terminal token issuance — #395), and each one wants the
 * same two fields spread into whatever body it already sends.
 *
 * Safe to call repeatedly, including from tests running in parallel: the
 * step-up verifies the code without consuming the timestep, unlike login, so
 * two specs may hold the same code inside one 30-second window.
 */

import { TEST_CREDENTIALS } from '../config/test-credentials'
import { generateTotp } from '../utils/totp'

export interface StepUpCredentials {
  current_password?: string
  totp_code?: string
}

export interface StepUpOptions {
  /** Defaults to the seeded admin's password; pass null to omit it entirely. */
  password?: string | null
  /** Defaults to a fresh code for the seeded admin; pass null to omit it. */
  totpCode?: string | null
}

export function stepUp(options: StepUpOptions = {}): StepUpCredentials {
  const {
    password = TEST_CREDENTIALS.admin.password,
    totpCode = generateTotp(TEST_CREDENTIALS.totp.adminSecret),
  } = options

  const credentials: StepUpCredentials = {}
  if (password !== null) credentials.current_password = password
  if (totpCode !== null) credentials.totp_code = totpCode

  return credentials
}
