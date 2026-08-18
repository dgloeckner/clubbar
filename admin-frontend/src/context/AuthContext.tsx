/**
 * Authentication Context
 * Provides global auth state and methods to all components
 */

import { createContext, useContext, useState, useEffect, useRef, ReactNode } from 'react'
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
import { setInsufficientRoleHandler } from '../api/client'
import type { AdminRole } from '../api/generated'

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
  /**
   * The roles this session holds (ADR-0044). Empty until the profile has been
   * read, and empty for an account that really holds none — both cases mean
   * "show nothing", which is the direction to fail in.
   */
  roles: AdminRole[]
}

// Callers must read the error off this result, not the `error` field below — by
// the time an await resolves, a render driven by the *previous* attempt's `error`
// may already have been captured in a stale closure (#133).
interface AuthResult {
  success: boolean
  error?: string
  /**
   * The roles the new session holds, on success. Handed back for the same
   * reason as `error` above: the caller navigates the moment this resolves,
   * and the `roles` it captured in its closure is still the previous render's.
   * Landing on the right page cannot depend on a state update that has not
   * happened yet.
   */
  roles?: AdminRole[]
}

interface AuthContextType extends AuthState {
  login: (credentials: LoginCredentials) => Promise<AuthResult>
  submitMfa: (code: string) => Promise<AuthResult>
  setupTotp: () => Promise<{ qrCode: string; secret: string }>
  confirmTotp: (code: string) => Promise<AuthResult>
  logout: () => Promise<void>
  /**
   * Sync a profile save (email/display_name/locale) into auth state. The
   * header and everywhere else that reads `displayName`/`email`/`locale`
   * from context render from this state, not from localStorage, so without
   * this call a rename or locale change stays invisible until a reload (#134).
   */
  updateProfile: (admin: { email: string; display_name: string; locale: string }) => void
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
  const [auth, setAuth] = useState<AuthState>({ isAuthenticated: false, roles: [] })
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
          roles: session.roles,
        })
      }
      setInitializing(false)
    })
    return () => {
      cancelled = true
    }
  }, [])

  /**
   * The state a freshly authenticated session starts in.
   *
   * Login, MFA and TOTP enrolment all answer with the account but not with its
   * roles, so the profile is read once here rather than a tick later: a render
   * that happens between "authenticated" and "roles known" would hide every
   * section and bounce the caller off the page they just logged in to.
   * A profile that will not load leaves the roles empty, and the refusal
   * screen says so.
   */
  const authenticated = async (admin: {
    admin_id: string
    email: string
    display_name: string
    locale: string
  }): Promise<AuthState> => {
    const session = await checkSession()

    return {
      isAuthenticated: true,
      adminId: admin.admin_id,
      email: admin.email,
      displayName: admin.display_name,
      locale: admin.locale,
      roles: session?.roles ?? [],
    }
  }

  /**
   * Re-read the roles after the server has refused something on role grounds.
   *
   * Only the roles: a 403 is not a dead session, so nothing else about the
   * auth state is touched, and a profile call that fails leaves the panel
   * showing what it already believed rather than logging anybody out.
   */
  const refreshRoles = async (): Promise<void> => {
    const session = await checkSession()
    if (session) {
      setAuth(prev => (prev.isAuthenticated ? { ...prev, roles: session.roles } : prev))
    }
  }

  // The profile request is itself gated by the role map, so a role-less
  // account's refresh would answer 403 and ask for another refresh. One in
  // flight at a time breaks that loop.
  const refreshing = useRef(false)

  useEffect(() => {
    setInsufficientRoleHandler(() => {
      if (refreshing.current) return
      refreshing.current = true
      void refreshRoles().finally(() => {
        refreshing.current = false
      })
    })

    return () => setInsufficientRoleHandler(null)
  }, [])

  const handleLogin = async (credentials: LoginCredentials): Promise<AuthResult> => {
    setLoading(true)
    setError(undefined)
    try {
      const response = await loginWithSession(credentials)
      if (response.success && response.data) {
        const next = await authenticated(response.data)
        setAuth(next)
        return { success: true, roles: next.roles }
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
        const next = {
          ...(await authenticated(pendingAdmin)),
          requiresTotpSetup: false,
          pendingAdmin: undefined,
        }
        setAuth(next)
        return { success: true, roles: next.roles }
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
        const next = { ...(await authenticated(response.data)), requiresMfa: false }
        setAuth(next)
        return { success: true, roles: next.roles }
      }
      const message = describe(response)
      setError(message)
      // The pending session is gone server-side — leaving the code form up would
      // invite guesses at a session that no longer exists. Back to the password.
      if (endsMfaStep(response.errorCode)) {
        setAuth({ isAuthenticated: false, requiresMfa: false, roles: [] })
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

  const updateProfile = (admin: { email: string; display_name: string; locale: string }): void => {
    setAuth(prev => (prev.isAuthenticated
      ? { ...prev, email: admin.email, displayName: admin.display_name, locale: admin.locale }
      : prev))
  }

  const handleLogout = async () => {
    setLoading(true)
    try {
      await logoutWithSession()
      setAuth({ isAuthenticated: false, roles: [] })
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
    updateProfile,
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
