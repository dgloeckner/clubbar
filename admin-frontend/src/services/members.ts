/**
 * Members API Service
 * Handles all member-related API calls
 */

import { api } from './api'

export interface Member {
  id: string
  email: string
  first_name: string
  last_name: string
  preferred_language: string
  is_active: boolean
  balance_cents: number
  card_uid?: string
  phone?: string
  street?: string
  city?: string
  postal_code?: string
  country?: string
  created_at: string
  updated_at: string
}

export interface MembersResponse {
  items: Member[]
  total: number
  page: number
  per_page: number
}

/**
 * Get list of members with pagination and filtering
 */
export async function getMembers(
  page: number = 1,
  perPage: number = 20,
  search?: string,
  filter?: { is_active?: boolean }
): Promise<MembersResponse> {
  const params: Record<string, any> = {
    page,
    per_page: perPage,
  }

  if (search) {
    params.search = search
  }

  if (filter?.is_active !== undefined) {
    params.is_active = filter.is_active
  }

  const response = await api.get('/admin/members', { params })
  return response.data
}

/**
 * Get a single member by ID
 */
export async function getMember(id: string): Promise<Member> {
  const response = await api.get(`/admin/members/${id}`)
  return response.data
}

/**
 * Create a new member
 */
export async function createMember(data: {
  email: string
  first_name: string
  last_name: string
  preferred_language?: string
  phone?: string
  street?: string
  city?: string
  postal_code?: string
  country?: string
}): Promise<Member> {
  const response = await api.post('/admin/members', data)
  return response.data
}

/**
 * Update an existing member
 */
export async function updateMember(id: string, data: Partial<Member>): Promise<Member> {
  const response = await api.patch(`/admin/members/${id}`, data)
  return response.data
}

/**
 * Deactivate a member (soft delete)
 */
export async function deactivateMember(id: string): Promise<Member> {
  const response = await api.patch(`/admin/members/${id}`, { is_active: false })
  return response.data
}

/**
 * Get member transaction history
 */
export async function getMemberTransactions(
  memberId: string,
  page: number = 1,
  perPage: number = 50
): Promise<any> {
  const response = await api.get(`/admin/members/${memberId}/transactions`, {
    params: { page, per_page: perPage },
  })
  return response.data
}
