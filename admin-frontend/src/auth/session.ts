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
    const response = await getAuthentication().login(credentials)

    localStorage.setItem('admin_id', response.admin_id)
    localStorage.setItem('email', response.email)
    localStorage.setItem('display_name', response.display_name)
    localStorage.setItem('locale', response.locale)
    setCsrfToken(response.csrf_token)
    changeLanguage(response.locale)

    return {
      success: true,
      message: 'Login successful',
      data: {
        admin_id: response.admin_id,
        email: response.email,
        display_name: response.display_name,
        locale: response.locale,
      },
    }
  } catch (error: unknown) {
    const message = axios.isAxiosError(error)
      ? (error.response?.data?.message as string | undefined) ?? 'Login failed'
      : 'Login failed'
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
