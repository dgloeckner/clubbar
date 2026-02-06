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
 * Note: Settlement types are unified (no longer have sepa vs manual distinction).
 * Use getSettlementStatus() from services/settlements.ts for status.
 */
export type SettlementStatus = 'active' | 'exported' | 'cancelled'

export interface Settlement {
  id: string
  settlement_date: string
  execution_date: string | null
  total_amount_cents: number
  total_amount_eur: number
  member_count: number
  is_cancelled: boolean
  exported_at: string | null
  created_at: string
  created_by_admin_id: string | null
  created_by_admin_name: string | null
}

export interface SettlementMember {
  id: string
  settlement_id: string
  member_id: string
  member?: Member
  amount_cents: number
  iban: string
  mandate_reference: string
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

/**
 * SEPA Configuration Types
 */
export interface SepaConfig {
  creditor_id: string
  creditor_name: string
  creditor_iban: string
  creditor_address_street: string
  creditor_address_city: string
  creditor_address_country: string
  payment_reference_prefix: string
  created_at: string
  updated_at: string
}

export interface UpdateSepaConfigRequest {
  creditor_id?: string
  creditor_name?: string
  creditor_iban?: string
  creditor_address_street?: string
  creditor_address_city?: string
  creditor_address_country?: string
  payment_reference_prefix?: string
}

/**
 * Admin User Types
 */
export interface AdminUser {
  id: string
  email: string
  display_name: string
  locale: string
  is_active: boolean
  last_login_at?: string
  created_at: string
  updated_at: string
}

export interface CreateAdminUserRequest {
  email: string
  display_name: string
  locale: string
}

export interface UpdateAdminUserRequest {
  email?: string
  display_name?: string
  locale?: string
}

export interface AdminUsersListResponse {
  data: AdminUser[]
  pagination: {
    total: number
    per_page: number
    current_page: number
    last_page: number
  }
}

export interface CreateAdminUserResponse {
  admin: AdminUser
  password: string
  message: string
}

export interface ResetPasswordResponse {
  admin: AdminUser
  password: string
  message: string
}
