/**
 * Session utilities — side effects that wrap generated API functions.
 *
 * The generated authentication functions handle HTTP. This file handles
 * the localStorage + CSRF + i18n side effects that happen around them.
 */

import axios from 'axios'
import { getAuthentication } from '../api/generated/authentication/authentication'
import axiosInstance, { setCsrfToken } from '../api/client'
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
  data?: {
    admin_id: string
    email: string
    display_name: string
    locale: string
  }
}

export async function loginWithSession(credentials: {
  email: string
  password: string
}): Promise<LoginSessionResult> {
  try {
    const rawResponse = await getAuthentication().login(credentials)
    // Backend returns {message, admin: {id, email, display_name, locale, last_login_at}, csrf_token}
    // The orval-generated LoginResponse type uses flat field names (admin_id, email, ...) which
    // don't match the actual response shape — access the real structure at runtime.
    const r = rawResponse as unknown as {
      requiresMfa?: boolean
      requiresTotpSetup?: boolean
      admin: { id: string; email: string; display_name: string; locale: string }
      csrf_token: string
    }

    if (r.requiresMfa) {
      return { success: false, requiresMfa: true, message: '' }
    }

    if (r.requiresTotpSetup) {
      // Store CSRF token so the setup/confirm endpoints can be called
      setCsrfToken(r.csrf_token)
      return {
        success: false,
        requiresTotpSetup: true,
        message: '',
        data: {
          admin_id: r.admin.id,
          email: r.admin.email,
          display_name: r.admin.display_name,
          locale: r.admin.locale,
        },
      }
    }

    const admin = r.admin

    localStorage.setItem('admin_id', admin.id)
    localStorage.setItem('email', admin.email)
    localStorage.setItem('display_name', admin.display_name)
    localStorage.setItem('locale', admin.locale)
    setCsrfToken(r.csrf_token)
    changeLanguage(admin.locale)

    return {
      success: true,
      message: 'Login successful',
      data: {
        admin_id: admin.id,
        email: admin.email,
        display_name: admin.display_name,
        locale: admin.locale,
      },
    }
  } catch (error: unknown) {
    const message = axios.isAxiosError(error)
      ? (error.response?.data?.message as string | undefined) ?? 'Login failed'
      : 'Login failed'
    return { success: false, message }
  }
}

// ─── MFA verification ─────────────────────────────────────────────────────────

export async function submitMfaWithSession(code: string): Promise<LoginSessionResult> {
  try {
    const rawResponse = await axiosInstance.post('/auth/mfa', { code })
    const r = rawResponse.data as {
      admin: { id: string; email: string; display_name: string; locale: string }
      csrf_token: string
    }
    const admin = r.admin

    localStorage.setItem('admin_id', admin.id)
    localStorage.setItem('email', admin.email)
    localStorage.setItem('display_name', admin.display_name)
    localStorage.setItem('locale', admin.locale)
    setCsrfToken(r.csrf_token)
    changeLanguage(admin.locale)

    return {
      success: true,
      message: 'Login successful',
      data: {
        admin_id: admin.id,
        email: admin.email,
        display_name: admin.display_name,
        locale: admin.locale,
      },
    }
  } catch (error: unknown) {
    const message = axios.isAxiosError(error)
      ? (error.response?.data?.message as string | undefined) ?? 'Invalid code'
      : 'Invalid code'
    return { success: false, message }
  }
}

// ─── TOTP enrollment ──────────────────────────────────────────────────────────

/** Fetch QR code + secret to display to the user during first-time 2FA setup. */
export async function setupTotpWithSession(): Promise<{ qrCode: string; secret: string }> {
  const resp = await axiosInstance.post('/auth/2fa/setup')
  return resp.data as { qrCode: string; secret: string }
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
    await axiosInstance.post('/auth/2fa/confirm', { code })

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
  const rawResponse = await getAuthentication().updateProfile(data)
  // Backend returns {message, admin: {id, email, display_name, locale, last_login_at}}
  // Unwrap the admin object from the response wrapper.
  const r = rawResponse as unknown as { admin: AdminProfile }
  const profile = r.admin ?? rawResponse
  if (profile) {
    if (profile.email) localStorage.setItem('email', profile.email)
    if (profile.display_name) localStorage.setItem('display_name', profile.display_name)
    if (profile.locale) localStorage.setItem('locale', profile.locale)
  }
  return profile
}

// Re-export getProfile, unwrapping the backend's response envelope.
export async function getProfile(): Promise<AdminProfile> {
  const rawResponse = await getAuthentication().getProfile()
  // Backend returns {admin: {id, email, display_name, locale, last_login_at}, csrf_token}
  // Unwrap the admin object.
  const r = rawResponse as unknown as { admin: AdminProfile }
  return r.admin ?? rawResponse
}
