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
      admin_id: string
      email: string
      display_name: string
      locale: string
    }>('/admin/auth/login', credentials)

    if (response.data) {
      // Store session info
      localStorage.setItem('admin_id', response.data.admin_id)
      localStorage.setItem('email', response.data.email)
      localStorage.setItem('display_name', response.data.display_name)
      localStorage.setItem('locale', response.data.locale)
    }

    return {
      success: true,
      message: 'Login successful',
      data: response.data,
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
    await post('/admin/auth/logout', {})
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
