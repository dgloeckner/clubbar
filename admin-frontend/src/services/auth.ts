/**
 * Authentication Service
 * Handles login, logout, and session management
 */

import { post, get, patch } from './api'
import { LoginCredentials, AuthResponse } from '../types'
import { changeLanguage } from '../i18n/config'

export interface AdminProfile {
  id: string
  email: string
  display_name: string
  locale: string
  last_login_at: string | null
}

export interface UpdateProfileRequest {
  email?: string
  display_name?: string
  locale?: string
}

export interface ChangePasswordRequest {
  new_password: string
  new_password_confirmation: string
}

/**
 * Login with credentials
 */
export async function login(credentials: LoginCredentials): Promise<AuthResponse> {
  try {
    const apiResponse = await post<{
      message: string
      admin: {
        id: string
        email: string
        display_name: string
        locale: string
      }
    }>('/auth/login', credentials)

    // Backend returns data directly or wrapped in ApiResponse
    // Handle both cases by checking for data property
    const response = (apiResponse.data ?? apiResponse) as unknown as {
      message: string
      admin?: {
        id: string
        email: string
        display_name: string
        locale: string
      }
    }

    // Backend returns { message, admin: { id, email, display_name, locale } }
    if (response.admin) {
      // Store session info
      localStorage.setItem('admin_id', response.admin.id)
      localStorage.setItem('email', response.admin.email)
      localStorage.setItem('display_name', response.admin.display_name)
      localStorage.setItem('locale', response.admin.locale)

      // Sync i18n language with user's saved preference
      const userLocale = response.admin.locale || 'de'
      changeLanguage(userLocale)
    }

    return {
      success: true,
      message: 'Login successful',
      data: response.admin ? {
        admin_id: response.admin.id,
        email: response.admin.email,
        display_name: response.admin.display_name,
        locale: response.admin.locale,
      } : undefined,
    }
  } catch (error: any) {
    const message = error.response?.data?.message || 'Login failed'
    return {
      success: false,
      message,
    }
  }
}

/**
 * Logout
 */
export async function logout(): Promise<void> {
  try {
    // Call logout endpoint
    await post('/auth/logout', {})
  } catch (error) {
    console.error('Logout error:', error)
  } finally {
    // Clear session storage regardless
    localStorage.removeItem('admin_id')
    localStorage.removeItem('email')
    localStorage.removeItem('display_name')
    localStorage.removeItem('locale')
  }
}

/**
 * Get current session from localStorage
 */
export function getCurrentSession() {
  return {
    adminId: localStorage.getItem('admin_id'),
    email: localStorage.getItem('email'),
    displayName: localStorage.getItem('display_name'),
    locale: localStorage.getItem('locale') || 'de-DE',
  }
}

/**
 * Check if user is authenticated
 */
export function isAuthenticated(): boolean {
  return !!localStorage.getItem('admin_id')
}

/**
 * Get current user's profile from API
 */
export async function getProfile(): Promise<AdminProfile> {
  const apiResponse = await get<{ admin: AdminProfile }>('/auth/profile')
  // Handle both wrapped and unwrapped responses
  const response = (apiResponse.data ?? apiResponse) as unknown as { admin: AdminProfile }
  return response.admin
}

/**
 * Update current user's profile
 */
export async function updateProfile(data: UpdateProfileRequest): Promise<AdminProfile> {
  const apiResponse = await patch<{ admin: AdminProfile; message: string }>('/auth/profile', data)

  // Handle both wrapped and unwrapped responses
  const response = (apiResponse.data ?? apiResponse) as unknown as { admin: AdminProfile; message: string }

  // Update localStorage with new values
  if (response.admin) {
    localStorage.setItem('email', response.admin.email)
    localStorage.setItem('display_name', response.admin.display_name)
    localStorage.setItem('locale', response.admin.locale)
  }

  return response.admin
}

/**
 * Change current user's password
 */
export async function changePassword(data: ChangePasswordRequest): Promise<void> {
  await patch<{ message: string }>('/auth/change-password', data)
}
