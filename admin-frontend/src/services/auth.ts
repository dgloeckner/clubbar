/**
 * Authentication Service
 * Handles login, logout, and session management
 */

import { post } from './api'
import { LoginCredentials, AuthResponse } from '../types'

/**
 * Login with credentials
 */
export async function login(credentials: LoginCredentials): Promise<AuthResponse> {
  try {
    const response = await post<{
      message: string
      admin: {
        id: string
        email: string
        display_name: string
        locale: string
      }
    }>('/auth/login', credentials)

    // Backend returns { message, admin: { id, email, display_name, locale } }
    if (response.admin) {
      // Store session info
      localStorage.setItem('admin_id', response.admin.id)
      localStorage.setItem('email', response.admin.email)
      localStorage.setItem('display_name', response.admin.display_name)
      localStorage.setItem('locale', response.admin.locale)
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
