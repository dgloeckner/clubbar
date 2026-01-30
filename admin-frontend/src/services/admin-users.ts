/**
 * Admin Users API Service
 * Handles admin user CRUD operations
 */

import { post, patch, del, get } from './api'
import { AdminUser, CreateAdminUserRequest, UpdateAdminUserRequest, AdminUsersListResponse, CreateAdminUserResponse, ResetPasswordResponse } from '../types'

/**
 * Get list of admin users with pagination and filtering
 */
export async function getAdminUsers(
  page: number = 1,
  perPage: number = 20,
  status: 'all' | 'active' | 'inactive' = 'all'
): Promise<AdminUsersListResponse> {
  const response = await get<AdminUsersListResponse>('/admin/admin-users', {
    params: {
      page,
      per_page: perPage,
      status,
    },
  })

  // The backend returns AdminUsersListResponse directly (not wrapped in ApiResponse.data)
  return response as unknown as AdminUsersListResponse
}

/**
 * Create a new admin user
 * Returns the created admin and auto-generated password
 */
export async function createAdminUser(data: CreateAdminUserRequest): Promise<CreateAdminUserResponse> {
  const response = await post<CreateAdminUserResponse>('/admin/admin-users', {
    email: data.email,
    display_name: data.display_name,
    locale: data.locale,
  })

  if (response.data) {
    return response.data
  }

  return response as unknown as CreateAdminUserResponse
}

/**
 * Get a single admin user by ID
 */
export async function getAdminUser(id: string): Promise<AdminUser | null> {
  try {
    const response = await get<{ admin: AdminUser }>(`/admin/admin-users/${id}`)
    if (response.data) {
      return response.data.admin
    }
    return null
  } catch (error) {
    return null
  }
}

/**
 * Update an admin user
 */
export async function updateAdminUser(id: string, data: UpdateAdminUserRequest): Promise<AdminUser> {
  const response = await patch<{ admin: AdminUser }>(`/admin/admin-users/${id}`, {
    email: data.email,
    display_name: data.display_name,
    locale: data.locale,
  })

  if (response.data) {
    return response.data.admin
  }

  throw new Error('Invalid response format')
}

/**
 * Deactivate an admin user
 */
export async function deactivateAdminUser(id: string): Promise<AdminUser> {
  const response = await del<{ admin: AdminUser }>(`/admin/admin-users/${id}`)

  if (response.data) {
    return response.data.admin
  }

  throw new Error('Invalid response format')
}

/**
 * Reactivate a deactivated admin user
 */
export async function reactivateAdminUser(id: string): Promise<AdminUser> {
  const response = await post<{ admin: AdminUser }>(`/admin/admin-users/${id}/reactivate`, {})

  if (response.data) {
    return response.data.admin
  }

  throw new Error('Invalid response format')
}

/**
 * Reset an admin user's password
 * Returns new password (shown only once)
 */
export async function resetAdminPassword(id: string): Promise<ResetPasswordResponse> {
  const response = await post<ResetPasswordResponse>(`/admin/admin-users/${id}/reset-password`, {})

  if (response.data) {
    return response.data
  }

  return response as unknown as ResetPasswordResponse
}
