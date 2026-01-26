/**
 * Members API Service
 * Handles all member-related API calls
 */

import { get, post, patch } from './api'

export interface Member {
  id: string
  email?: string
  first_name: string
  last_name: string
  iban: string
  mandate_signed_at: string
  preferred_language: string
  is_active: boolean
  balance_cents: number
  card_uid?: string
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

  const response = await get<MembersResponse>('/admin/members', { params })
  return response.data || { items: [], total: 0, page: 1, per_page: perPage }
}

/**
 * Get a single member by ID
 */
export async function getMember(id: string): Promise<Member> {
  const response = await get<Member>(`/admin/members/${id}`)
  return response.data as Member
}

/**
 * Create a new member
 * Required fields per UC-A11: first_name, last_name, iban, mandate_signed_at, preferred_language
 */
export async function createMember(data: {
  first_name: string
  last_name: string
  iban: string
  mandate_signed_at: string
  preferred_language: string
  email?: string
}): Promise<Member> {
  const response = await post<Member>('/admin/members', data)
  return response.data as Member
}

/**
 * Update an existing member
 */
export async function updateMember(id: string, data: Partial<Member>): Promise<Member> {
  const response = await patch<Member>(`/admin/members/${id}`, data)
  return response.data as Member
}

/**
 * Deactivate a member (soft delete)
 */
export async function deactivateMember(id: string): Promise<Member> {
  const response = await patch<Member>(`/admin/members/${id}`, { is_active: false })
  return response.data as Member
}

/**
 * Get member transaction history
 */
export async function getMemberTransactions(
  memberId: string,
  page: number = 1,
  perPage: number = 50
): Promise<any> {
  const response = await get<any>(`/admin/members/${memberId}/transactions`, {
    params: { page, per_page: perPage },
  })
  return response.data
}
