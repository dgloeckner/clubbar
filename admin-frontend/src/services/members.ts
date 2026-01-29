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
 * Get list of members with pagination, filtering, and sorting
 */
export async function getMembers(
  page: number = 1,
  perPage: number = 20,
  search?: string,
  filter?: { is_active?: boolean },
  sort: string = 'created_at',
  order: 'asc' | 'desc' = 'desc'
): Promise<MembersResponse> {
  const params: Record<string, any> = {
    page,
    per_page: perPage,
    sort,
    order,
  }

  if (search) {
    params.search = search
  }

  if (filter?.is_active !== undefined) {
    params.is_active = filter.is_active
  }

  const response = await get<MembersResponse>('/admin/members', { params })

  // Backend returns data directly, not wrapped in ApiResponse
  // Check if response has items (unwrapped) or data property (wrapped)
  if (response && typeof response === 'object') {
    if ('items' in response && Array.isArray(response.items)) {
      return response as MembersResponse
    }
    if ('data' in response && response.data) {
      return response.data as MembersResponse
    }
  }

  return { items: [], total: 0, page: 1, per_page: perPage }
}

/**
 * Get a single member by ID
 */
export async function getMember(id: string): Promise<Member> {
  const response = await get<Member>(`/admin/members/${id}`)

  // Backend returns member object directly
  if (response && typeof response === 'object') {
    if ('id' in response && 'first_name' in response) {
      return response as Member
    }
    if ('data' in response && response.data) {
      return response.data as Member
    }
  }

  throw new Error('Invalid response from get member API')
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

  // Backend returns member object directly, not wrapped in ApiResponse
  if (response && typeof response === 'object') {
    if ('id' in response && 'first_name' in response) {
      return response as Member
    }
    if ('data' in response && response.data) {
      return response.data as Member
    }
  }

  throw new Error('Invalid response from member creation API')
}

/**
 * Update an existing member
 */
export async function updateMember(id: string, data: Partial<Member>): Promise<Member> {
  const response = await patch<Member>(`/admin/members/${id}`, data)

  // Backend returns member object directly
  if (response && typeof response === 'object') {
    if ('id' in response && 'first_name' in response) {
      return response as Member
    }
    if ('data' in response && response.data) {
      return response.data as Member
    }
  }

  throw new Error('Invalid response from update member API')
}

/**
 * Deactivate a member (soft delete)
 */
export async function deactivateMember(id: string): Promise<Member> {
  const response = await patch<Member>(`/admin/members/${id}`, { is_active: false })

  // Backend returns member object directly
  if (response && typeof response === 'object') {
    if ('id' in response && 'first_name' in response) {
      return response as Member
    }
    if ('data' in response && response.data) {
      return response.data as Member
    }
  }

  throw new Error('Invalid response from deactivate member API')
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

  // Backend returns data directly
  if (response && typeof response === 'object') {
    if ('items' in response) {
      return response
    }
    if ('data' in response && response.data) {
      return response.data
    }
  }

  return { items: [], total: 0, page: 1, per_page: perPage }
}
