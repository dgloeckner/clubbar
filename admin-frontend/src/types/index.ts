/**
 * Authentication Types
 */
export interface LoginCredentials {
  email: string
  password: string
}

export interface AuthResponse {
  success: boolean
  message: string
  data?: {
    admin_id: string
    email: string
    display_name: string
    locale: string
  }
}

export interface AuthState {
  isAuthenticated: boolean
  adminId?: string
  email?: string
  displayName?: string
  locale?: string
}

/**
 * Member Types
 */
export interface Member {
  id: string
  first_name: string
  last_name: string
  rfid_card: string
  iban: string
  bic: string
  balance: number
  preferred_language: string
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface CreateMemberRequest {
  first_name: string
  last_name: string
  rfid_card: string
  iban: string
  bic: string
  preferred_language: string
}

export interface UpdateMemberRequest {
  first_name?: string
  last_name?: string
  rfid_card?: string
  iban?: string
  bic?: string
  preferred_language?: string
}

/**
 * Product Types
 */
export interface Category {
  id: string
  name: Record<string, string>
  is_active: boolean
  created_at: string
}

export interface Product {
  id: string
  name: Record<string, string>
  description?: Record<string, string>
  sku: string
  price_cents: number
  category_id: string
  category?: Category
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface CreateProductRequest {
  name: Record<string, string>
  description?: Record<string, string>
  sku: string
  price_cents: number
  category_id: string
}

export interface UpdateProductRequest {
  name?: Record<string, string>
  description?: Record<string, string>
  sku?: string
  price_cents?: number
  category_id?: string
}

/**
 * Transaction Types
 */
export type TransactionType = 'purchase' | 'manual_adjustment' | 'reversal' | 'correction'

export interface Transaction {
  id: string
  member_id: string
  member?: Member
  product_id?: string
  product?: Product
  amount_cents: number
  type: TransactionType
  notes?: string
  synced_at?: string
  created_at: string
}

/**
 * Settlement Types
 */
export type SettlementType = 'manual' | 'sepa_direct_debit'
export type SettlementStatus = 'draft' | 'pending' | 'completed' | 'failed'

export interface Settlement {
  id: string
  settlement_type: SettlementType
  status: SettlementStatus
  total_amount_cents: number
  member_count: number
  execution_date: string
  created_at: string
  updated_at: string
}

export interface SettlementMember {
  id: string
  settlement_id: string
  member_id: string
  member?: Member
  amount_cents: number
  iban: string
  mandate_reference: string
  status: 'pending' | 'processed' | 'failed'
}

/**
 * Dashboard Types
 */
export interface DashboardMetrics {
  active_members: number
  inactive_members: number
  total_members: number
  total_balance_cents: number
  average_balance_cents: number
  top_spenders: Member[]
  recent_transactions: Transaction[]
  last_settlement_at?: string
  active_terminals: number
  pending_settlements: number
}

/**
 * API Response Wrapper
 */
export interface ApiResponse<T> {
  success: boolean
  data?: T
  error?: string
  message?: string
  pagination?: {
    page: number
    per_page: number
    total: number
    total_pages: number
  }
}

/**
 * Pagination
 */
export interface PaginationParams {
  page: number
  per_page: number
}

export interface PaginatedResponse<T> {
  data: T[]
  pagination: {
    page: number
    per_page: number
    total: number
    total_pages: number
  }
}
