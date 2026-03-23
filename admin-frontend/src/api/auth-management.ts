/**
 * Auth management API — hand-written (not orval-generated)
 * Covers admin-initiated auth operations on other users.
 */
import { customInstance } from './client'

/**
 * Reset TOTP 2FA for a given admin user.
 * POST /api/auth/2fa/reset
 * Requires: active authenticated session + CSRF token (injected by customInstance)
 */
export function reset2fa(userId: string): Promise<{ message: string }> {
  return customInstance({ url: '/auth/2fa/reset', method: 'POST', data: { userId } })
}
