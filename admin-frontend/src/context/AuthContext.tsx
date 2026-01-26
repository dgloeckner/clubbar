/**
 * Authentication Context
 * Provides global auth state and methods to all components
 */

import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react'
import { AuthState, LoginCredentials } from '../types'
import { login as loginService, logout as logoutService, getCurrentSession, isAuthenticated } from '../services/auth'

interface AuthContextType extends AuthState {
  login: (credentials: LoginCredentials) => Promise<boolean>
  logout: () => Promise<void>
  loading: boolean
  error?: string
}

const AuthContext = createContext<AuthContextType | undefined>(undefined)

interface AuthProviderProps {
  children: ReactNode
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [auth, setAuth] = useState<AuthState>({
    isAuthenticated: false,
  })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string>()

  // Check authentication on mount
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
      const response = await loginService(credentials)
      if (response.success && response.data) {
        setAuth({
          isAuthenticated: true,
          adminId: response.data.admin_id,
          email: response.data.email,
          displayName: response.data.display_name,
          locale: response.data.locale,
        })
        return true
      } else {
        setError(response.message)
        return false
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Login failed'
      setError(message)
      return false
    } finally {
      setLoading(false)
    }
  }

  const handleLogout = async () => {
    setLoading(true)
    try {
      await logoutService()
      setAuth({
        isAuthenticated: false,
      })
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
    logout: handleLogout,
    loading,
    error,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

/**
 * Hook to use AuthContext
 */
export function useAuth(): AuthContextType {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider')
  }
  return context
}
