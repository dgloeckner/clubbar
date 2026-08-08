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

// ─── Read-only session helpers ────────────────────────────────────────────────

export function getCurrentSession() {
  return {
    adminId: localStorage.getItem('admin_id'),
    email: localStorage.getItem('email'),
    displayName: localStorage.getItem('display_name'),
    // 'de' (not 'de-DE') — i18n/config.ts only accepts ISO 639-1 codes
    locale: localStorage.getItem('locale') || 'de',
  }
}

export function isAuthenticated(): boolean {
  return !!localStorage.getItem('admin_id')
}

// ─── Login ────────────────────────────────────────────────────────────────────

export interface LoginSessionResult {
  success: boolean
  requiresMfa?: boolean
  requiresTotpSetup?: boolean
  message: string
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
function toFailure(error: unknown, fallbackMessage: string): LoginSessionResult {
  if (!axios.isAxiosError(error)) {
    return { success: false, message: fallbackMessage }
  }
  const data = error.response?.data as { error?: string; message?: string } | undefined
  return {
    success: false,
    errorCode: data?.error,
    message: data?.message ?? fallbackMessage,
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
      return { success: false, message: 'Login failed' }
    }

    storeAdmin(r.admin, r.csrf_token)

    return {
      success: true,
      message: 'Login successful',
      data: toLoginData(r.admin),
    }
  } catch (error: unknown) {
    return toFailure(error, 'Login failed')
  }
}

// ─── MFA verification ─────────────────────────────────────────────────────────

export async function submitMfaWithSession(code: string): Promise<LoginSessionResult> {
  try {
    const r = await getAuthentication().verifyMfa({ code })
    storeAdmin(r.admin, r.csrf_token)

    return {
      success: true,
      message: 'Login successful',
      data: toLoginData(r.admin),
    }
  } catch (error: unknown) {
    // The error code matters here: five wrong codes destroy the pending session
    // server-side (#78), and asking for a sixth would be asking into the void.
    return toFailure(error, 'Invalid code')
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
): Promise<{ success: boolean; message: string }> {
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
      ? (error.response?.data?.message as string | undefined) ?? 'Invalid code'
      : 'Invalid code'
    return { success: false, message }
  }
}

// ─── Logout ───────────────────────────────────────────────────────────────────

export async function logoutWithSession(): Promise<void> {
  try {
    await getAuthentication().logout()
  } catch {
    // Swallow errors — session is cleared regardless
  } finally {
    localStorage.removeItem('admin_id')
    localStorage.removeItem('email')
    localStorage.removeItem('display_name')
    localStorage.removeItem('locale')
    setCsrfToken(null) // also removes 'csrf_token' from localStorage
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

// Re-export getProfile, unwrapping the backend's response envelope.
export async function getProfile(): Promise<AdminProfile> {
  const { admin } = await getAuthentication().getProfile()
  return admin
}
