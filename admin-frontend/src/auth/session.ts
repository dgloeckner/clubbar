/**
 * Session utilities — side effects that wrap generated API functions.
 *
 * The generated authentication functions handle HTTP. This file handles
 * the localStorage + CSRF + i18n side effects that happen around them.
 */

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

export interface LoginResult {
  success: boolean
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
}): Promise<LoginResult> {
  try {
    const response = await getAuthentication().login({ email: credentials.email, password: credentials.password })

    if (response.admin_id) {
      localStorage.setItem('admin_id', response.admin_id)
      localStorage.setItem('email', response.email ?? '')
      localStorage.setItem('display_name', response.display_name ?? '')
      localStorage.setItem('locale', response.locale ?? 'de')

      if (response.csrf_token) {
        setCsrfToken(response.csrf_token)
      }

      const locale = response.locale || 'de'
      changeLanguage(locale)

      return {
        success: true,
        message: 'Login successful',
        data: {
          admin_id: response.admin_id,
          email: response.email ?? '',
          display_name: response.display_name ?? '',
          locale,
        },
      }
    }

    return { success: false, message: 'Login failed' }
  } catch (error: any) {
    const message = error.response?.data?.message || 'Login failed'
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
    setCsrfToken(null)
  }
}

// ─── Profile update ───────────────────────────────────────────────────────────

export async function updateProfileWithSession(
  data: UpdateProfileRequest
): Promise<AdminProfile> {
  const profile = await getAuthentication().updateProfile(data)
  if (profile) {
    if (profile.email) localStorage.setItem('email', profile.email)
    if (profile.display_name) localStorage.setItem('display_name', profile.display_name)
    if (profile.locale) localStorage.setItem('locale', profile.locale)
  }
  return profile
}

// Re-export getProfile for convenience (no side effects needed)
export function getProfile() {
  return getAuthentication().getProfile()
}
