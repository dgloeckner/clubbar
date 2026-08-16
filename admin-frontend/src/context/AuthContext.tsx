/**
 * Authentication Context
 * Provides global auth state and methods to all components
 */

import { createContext, useContext, useState, useEffect, ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import {
  loginWithSession,
  submitMfaWithSession,
  setupTotpWithSession,
  confirmTotpWithSession,
  logoutWithSession,
  checkSession,
} from '../auth/session'
import { authErrorKey, endsMfaStep } from '../utils/authErrors'

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

// Callers must read the error off this result, not the `error` field below — by
// the time an await resolves, a render driven by the *previous* attempt's `error`
// may already have been captured in a stale closure (#133).
interface AuthResult {
  success: boolean
  error?: string
}

interface AuthContextType extends AuthState {
  login: (credentials: LoginCredentials) => Promise<AuthResult>
  submitMfa: (code: string) => Promise<AuthResult>
  setupTotp: () => Promise<{ qrCode: string; secret: string }>
  confirmTotp: (code: string) => Promise<AuthResult>
  logout: () => Promise<void>
  // True only while the app is checking for an existing session on first load.
  // Routing (App.tsx) gates its full-page loading screen on this, NOT on `loading`.
  initializing: boolean
  // True while a login/MFA/TOTP/logout request is in flight. Login-form-local UI
  // (disabled buttons, spinner text) reads this — routing must not, or a login
  // attempt would unmount/remount LoginPage mid-submission and drop its local state
  // (e.g. the error message set right after the request resolves).
  loading: boolean
  error?: string
}

const AuthContext = createContext<AuthContextType | undefined>(undefined)

interface AuthProviderProps {
  children: ReactNode
}

export function AuthProvider({ children }: AuthProviderProps) {
  const { t } = useTranslation()
  const [auth, setAuth] = useState<AuthState>({ isAuthenticated: false })
  const [initializing, setInitializing] = useState(true)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string>()

  // Lockout outcomes get localized wording of their own; otherwise the server's
  // message is shown, and when it sent none, the key session.ts named for the
  // failure. Nothing here is ever an untranslated English literal.
  const describe = (response: { errorCode?: string; message: string; messageKey?: string }): string => {
    const key = authErrorKey(response.errorCode) ?? response.messageKey
    return key ? t(key) : response.message
  }

  useEffect(() => {
    // A localStorage flag can't tell a live session from one whose cookie
    // already expired server-side (#109) — ask the backend instead. Failure
    // (401 or otherwise) leaves `auth` at its logged-out default and
    // checkSession() has already cleared any stale PII/CSRF from storage.
    let cancelled = false
    checkSession().then((session) => {
      if (cancelled) return
      if (session) {
        setAuth({
          isAuthenticated: true,
          adminId: session.admin_id,
          email: session.email,
          displayName: session.display_name,
          locale: session.locale,
        })
      }
      setInitializing(false)
    })
    return () => {
      cancelled = true
    }
  }, [])

  const handleLogin = async (credentials: LoginCredentials): Promise<AuthResult> => {
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
        return { success: true }
      }
      if (response.requiresMfa) {
        setAuth(prev => ({ ...prev, requiresMfa: true }))
        return { success: false }
      }
      if (response.requiresTotpSetup) {
        setAuth(prev => ({ ...prev, requiresTotpSetup: true, pendingAdmin: response.data }))
        return { success: false }
      }
      const message = describe(response)
      setError(message)
      return { success: false, error: message }
    } catch (err) {
      const message = err instanceof Error ? err.message : t('auth.loginFailed')
      setError(message)
      return { success: false, error: message }
    } finally {
      setLoading(false)
    }
  }

  const handleSetupTotp = async (): Promise<{ qrCode: string; secret: string }> => {
    return setupTotpWithSession()
  }

  const handleConfirmTotp = async (code: string): Promise<AuthResult> => {
    const pendingAdmin = auth.pendingAdmin
    if (!pendingAdmin) return { success: false }

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
        return { success: true }
      }
      const message = describe(result)
      setError(message)
      return { success: false, error: message }
    } catch (err) {
      const message = err instanceof Error ? err.message : t('auth.mfaInvalidCode')
      setError(message)
      return { success: false, error: message }
    } finally {
      setLoading(false)
    }
  }

  const handleSubmitMfa = async (code: string): Promise<AuthResult> => {
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
        return { success: true }
      }
      const message = describe(response)
      setError(message)
      // The pending session is gone server-side — leaving the code form up would
      // invite guesses at a session that no longer exists. Back to the password.
      if (endsMfaStep(response.errorCode)) {
        setAuth({ isAuthenticated: false, requiresMfa: false })
      }
      return { success: false, error: message }
    } catch (err) {
      const message = err instanceof Error ? err.message : t('auth.mfaInvalidCode')
      setError(message)
      return { success: false, error: message }
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
      setError(err instanceof Error ? err.message : t('auth.logoutFailed'))
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
    initializing,
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
