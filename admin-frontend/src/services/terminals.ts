/**
 * Terminals API Service
 * Handles terminal device CRUD and token operations
 */

import { get, post, patch, del } from './api'
import {
  Terminal,
  CreateTerminalRequest,
  UpdateTerminalRequest,
  TerminalsListResponse,
  CreateTerminalResponse,
  RotateTokenResponse,
} from '../types'

/**
 * Get list of terminals with pagination and filtering
 */
export async function getTerminals(
  page: number = 1,
  perPage: number = 50,
  isActive?: boolean
): Promise<TerminalsListResponse> {
  const params: Record<string, any> = { page, per_page: perPage }
  if (isActive !== undefined) {
    params.is_active = isActive
  }
  const response = await get<TerminalsListResponse>('/admin/terminals', { params })
  return response as unknown as TerminalsListResponse
}

/**
 * Create a new terminal
 * Returns terminal and API token (shown only once)
 */
export async function createTerminal(data: CreateTerminalRequest): Promise<CreateTerminalResponse> {
  const response = await post<CreateTerminalResponse>('/admin/terminals', data)
  if (response.data) {
    return response.data
  }
  return response as unknown as CreateTerminalResponse
}

/**
 * Get a single terminal by ID
 */
export async function getTerminal(id: string): Promise<Terminal | null> {
  try {
    const response = await get<{ terminal: Terminal }>(`/admin/terminals/${id}`)
    const responseAny = response as any
    if (responseAny.terminal) return responseAny.terminal
    if (response.data) return (response.data as any).terminal
    return null
  } catch {
    return null
  }
}

/**
 * Update a terminal (name or is_active)
 */
export async function updateTerminal(id: string, data: UpdateTerminalRequest): Promise<Terminal> {
  const response = await patch<{ terminal: Terminal }>(`/admin/terminals/${id}`, data)
  const responseAny = response as any
  if (responseAny.terminal) return responseAny.terminal
  if (response.data) return (response.data as any).terminal
  throw new Error('Invalid response format')
}

/**
 * Delete (soft-deactivate) a terminal
 */
export async function deleteTerminal(id: string): Promise<void> {
  await del(`/admin/terminals/${id}`)
}

/**
 * Rotate terminal API token
 * Returns new token (shown only once)
 */
export async function rotateTerminalToken(id: string): Promise<RotateTokenResponse> {
  const response = await post<RotateTokenResponse>(`/admin/terminals/${id}/rotate-token`, {})
  if (response.data) return response.data
  return response as unknown as RotateTokenResponse
}

/**
 * Revoke terminal access (clears token + deactivates)
 */
export async function revokeTerminalAccess(id: string): Promise<void> {
  await post(`/admin/terminals/${id}/revoke`, {})
}
