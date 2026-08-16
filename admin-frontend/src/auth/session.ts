/**
 * Session utilities — side effects that wrap generated API functions.
 *
 * The generated authentication functions handle HTTP. This file handles
 * the localStorage + CSRF + i18n side effects that happen around them.
 */

import axios from 'axios'
import { getAuthentication } from '../api/generated/authentication/authentication'
import { setCsrfToken } from '../api/client'
import { changeLanguage } from '../i18n/config'
import type { UpdateProfileRequest, AdminProfile } from '../api/generated'

// ─── Session check ─────────────────────────────────────────────────────────────

export interface SessionCheckResult {
  admin_id: string
  email: string
  display_name: string
  locale: string
}

/**
 * Boot-time source of truth for whether the server session is alive.
 *
 * A localStorage flag can't distinguish a live session from one whose cookie
 * already expired server-side, and it would leave a working-looking UI shell
 * on screen that fails on the first real request. Asking the backend closes
 * that gap, and doubles as the cleanup an expired session never otherwise
 * triggers (#109): failure clears whatever PII/CSRF is left in storage.
 */
export async function checkSession(): Promise<SessionCheckResult | null> {
  try {
    const r = await getAuthentication().getProfile()
    if (r.csrf_token) setCsrfToken(r.csrf_token)
    localStorage.setItem('admin_id', r.admin.id)
    localStorage.setItem('email', r.admin.email)
    localStorage.setItem('display_name', r.admin.display_name)
    localStorage.setItem('locale', r.admin.locale)
    changeLanguage(r.admin.locale)
    return {
      admin_id: r.admin.id,
      email: r.admin.email,
      display_name: r.admin.display_name,
      locale: r.admin.locale,
    }
  } catch {
    clearStoredSession()
    return null
  }
}

export function clearStoredSession(): void {
  localStorage.removeItem('admin_id')
  localStorage.removeItem('email')
  localStorage.removeItem('display_name')
  localStorage.removeItem('locale')
  setCsrfToken(null)
}

// ─── Login ────────────────────────────────────────────────────────────────────

export interface LoginSessionResult {
  success: boolean
  requiresMfa?: boolean
  requiresTotpSetup?: boolean
  /**
   * What the API said went wrong, verbatim. Empty when it said nothing usable —
   * this module has no `t` of its own, so the wording for that case travels as
   * `messageKey` and the caller (AuthContext) translates it.
   */
  message: string
  /** i18n key describing the failure, set only when `message` is empty. */
  messageKey?: string
  /** API error code (e.g. `mfa_attempts_exceeded`), when the request failed with one. */
  errorCode?: string
  data?: {
    admin_id: string
    email: string
    display_name: string
    locale: string
  }
}

/** Pull the API's error code and message out of a failed request. */
function toFailure(error: unknown, fallbackKey: string): LoginSessionResult {
  if (!axios.isAxiosError(error)) {
    return { success: false, message: '', messageKey: fallbackKey }
  }
  const data = error.response?.data as { error?: string; message?: string } | undefined
  const message = data?.message?.trim()
  return {
    success: false,
    errorCode: data?.error,
    message: message ?? '',
    messageKey: message ? undefined : fallbackKey,
  }
}

function storeAdmin(admin: AdminProfile, csrfToken?: string): void {
  localStorage.setItem('admin_id', admin.id)
  localStorage.setItem('email', admin.email)
  localStorage.setItem('display_name', admin.display_name)
  localStorage.setItem('locale', admin.locale)
  if (csrfToken) setCsrfToken(csrfToken)
  changeLanguage(admin.locale)
}

function toLoginData(admin: AdminProfile): NonNullable<LoginSessionResult['data']> {
  return {
    admin_id: admin.id,
    email: admin.email,
    display_name: admin.display_name,
    locale: admin.locale,
  }
}

export async function loginWithSession(credentials: {
  email: string
  password: string
}): Promise<LoginSessionResult> {
  try {
    const r = await getAuthentication().login(credentials)

    if (r.requiresMfa) {
      return { success: false, requiresMfa: true, message: '' }
    }

    if (r.requiresTotpSetup && r.admin) {
      // Store CSRF token so the setup/confirm endpoints can be called
      setCsrfToken(r.csrf_token ?? null)
      return {
        success: false,
        requiresTotpSetup: true,
        message: '',
        data: toLoginData(r.admin),
      }
    }

    if (!r.admin) {
      return { success: false, message: '', messageKey: 'auth.loginFailed' }
    }

    storeAdmin(r.admin, r.csrf_token)

    return {
      success: true,
      message: '',
      data: toLoginData(r.admin),
    }
  } catch (error: unknown) {
    return toFailure(error, 'auth.loginFailed')
  }
}

// ─── MFA verification ─────────────────────────────────────────────────────────

export async function submitMfaWithSession(code: string): Promise<LoginSessionResult> {
  try {
    const r = await getAuthentication().verifyMfa({ code })
    storeAdmin(r.admin, r.csrf_token)

    return {
      success: true,
      message: '',
      data: toLoginData(r.admin),
    }
  } catch (error: unknown) {
    // The error code matters here: five wrong codes destroy the pending session
    // server-side (#78), and asking for a sixth would be asking into the void.
    return toFailure(error, 'auth.mfaInvalidCode')
  }
}

// ─── TOTP enrollment ──────────────────────────────────────────────────────────

/** Fetch QR code + secret to display to the user during first-time 2FA setup. */
export async function setupTotpWithSession(): Promise<{ qrCode: string; secret: string }> {
  return getAuthentication().setupTotp()
}

/**
 * Confirm enrollment with the first TOTP code.
 * On success, writes admin data to localStorage and sets the language.
 */
export async function confirmTotpWithSession(
  code: string,
  adminData: { admin_id: string; email: string; display_name: string; locale: string }
): Promise<{ success: boolean; message: string; messageKey?: string }> {
  try {
    await getAuthentication().confirmTotp({ code })

    localStorage.setItem('admin_id', adminData.admin_id)
    localStorage.setItem('email', adminData.email)
    localStorage.setItem('display_name', adminData.display_name)
    localStorage.setItem('locale', adminData.locale)
    changeLanguage(adminData.locale)

    return { success: true, message: '' }
  } catch (error: unknown) {
    const message = axios.isAxiosError(error)
      ? (error.response?.data?.message as string | undefined)?.trim()
      : undefined
    return {
      success: false,
      message: message ?? '',
      messageKey: message ? undefined : 'auth.mfaInvalidCode',
    }
  }
}

// ─── Logout ───────────────────────────────────────────────────────────────────

export async function logoutWithSession(): Promise<void> {
  try {
    await getAuthentication().logout()
  } catch {
    // Swallow errors — session is cleared regardless
  } finally {
    clearStoredSession()
  }
}

// ─── Profile update ───────────────────────────────────────────────────────────

/**
 * Wraps generated updateProfile(). Writes email/display_name/locale back to localStorage on success.
 * Throws on API error — callers must handle exceptions.
 */
export async function updateProfileWithSession(
  data: UpdateProfileRequest
): Promise<AdminProfile> {
  const { admin } = await getAuthentication().updateProfile(data)
  localStorage.setItem('email', admin.email)
  localStorage.setItem('display_name', admin.display_name)
  localStorage.setItem('locale', admin.locale)
  return admin
}

// Re-export getProfile, unwrapping the backend's response envelope. Also
// refreshes the in-memory CSRF token (#109) — every profile fetch is a free
// opportunity to keep it current without touching localStorage.
export async function getProfile(): Promise<AdminProfile> {
  const { admin, csrf_token } = await getAuthentication().getProfile()
  if (csrf_token) setCsrfToken(csrf_token)
  return admin
}
