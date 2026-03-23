/**
 * Authentication Context
 * Provides global auth state and methods to all components
 */

import { createContext, useContext, useState, useEffect, ReactNode } from 'react'
import {
  loginWithSession,
  submitMfaWithSession,
  setupTotpWithSession,
  confirmTotpWithSession,
  logoutWithSession,
  getCurrentSession,
  isAuthenticated,
} from '../auth/session'

// UI-level types — not from generated schemas
interface LoginCredentials {
  email: string
  password: string
}

interface AuthState {
  isAuthenticated: boolean
  requiresMfa?: boolean
  requiresTotpSetup?: boolean
  pendingAdmin?: { admin_id: string; email: string; display_name: string; locale: string }
  adminId?: string
  email?: string
  displayName?: string
  locale?: string
}

interface AuthContextType extends AuthState {
  login: (credentials: LoginCredentials) => Promise<boolean>
  submitMfa: (code: string) => Promise<boolean>
  setupTotp: () => Promise<{ qrCode: string; secret: string }>
  confirmTotp: (code: string) => Promise<boolean>
  logout: () => Promise<void>
  loading: boolean
  error?: string
}

const AuthContext = createContext<AuthContextType | undefined>(undefined)

interface AuthProviderProps {
  children: ReactNode
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [auth, setAuth] = useState<AuthState>({ isAuthenticated: false })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string>()

  useEffect(() => {
    const session = getCurrentSession()
    if (isAuthenticated()) {
      setAuth({
        isAuthenticated: true,
        adminId: session.adminId || undefined,
        email: session.email || undefined,
        displayName: session.displayName || undefined,
        locale: session.locale,
      })
    }
    setLoading(false)
  }, [])

  const handleLogin = async (credentials: LoginCredentials): Promise<boolean> => {
    setLoading(true)
    setError(undefined)
    try {
      const response = await loginWithSession(credentials)
      if (response.success && response.data) {
        setAuth({
          isAuthenticated: true,
          adminId: response.data.admin_id,
          email: response.data.email,
          displayName: response.data.display_name,
          locale: response.data.locale,
        })
        return true
      }
      if (response.requiresMfa) {
        setAuth(prev => ({ ...prev, requiresMfa: true }))
        return false
      }
      if (response.requiresTotpSetup) {
        setAuth(prev => ({ ...prev, requiresTotpSetup: true, pendingAdmin: response.data }))
        return false
      }
      setError(response.message)
      return false
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Login failed'
      setError(message)
      return false
    } finally {
      setLoading(false)
    }
  }

  const handleSetupTotp = async (): Promise<{ qrCode: string; secret: string }> => {
    return setupTotpWithSession()
  }

  const handleConfirmTotp = async (code: string): Promise<boolean> => {
    const pendingAdmin = auth.pendingAdmin
    if (!pendingAdmin) return false

    setLoading(true)
    setError(undefined)
    try {
      const result = await confirmTotpWithSession(code, pendingAdmin)
      if (result.success) {
        setAuth({
          isAuthenticated: true,
          requiresTotpSetup: false,
          pendingAdmin: undefined,
          adminId: pendingAdmin.admin_id,
          email: pendingAdmin.email,
          displayName: pendingAdmin.display_name,
          locale: pendingAdmin.locale,
        })
        return true
      }
      setError(result.message)
      return false
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Invalid code'
      setError(message)
      return false
    } finally {
      setLoading(false)
    }
  }

  const handleSubmitMfa = async (code: string): Promise<boolean> => {
    setLoading(true)
    setError(undefined)
    try {
      const response = await submitMfaWithSession(code)
      if (response.success && response.data) {
        setAuth({
          isAuthenticated: true,
          requiresMfa: false,
          adminId: response.data.admin_id,
          email: response.data.email,
          displayName: response.data.display_name,
          locale: response.data.locale,
        })
        return true
      }
      setError(response.message)
      return false
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Invalid code'
      setError(message)
      return false
    } finally {
      setLoading(false)
    }
  }

  const handleLogout = async () => {
    setLoading(true)
    try {
      await logoutWithSession()
      setAuth({ isAuthenticated: false })
      setError(undefined)
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Logout failed'
      setError(message)
    } finally {
      setLoading(false)
    }
  }

  const value: AuthContextType = {
    ...auth,
    login: handleLogin,
    submitMfa: handleSubmitMfa,
    setupTotp: handleSetupTotp,
    confirmTotp: handleConfirmTotp,
    logout: handleLogout,
    loading,
    error,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextType {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used within AuthProvider')
  return context
}
