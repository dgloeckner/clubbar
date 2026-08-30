/**
 * Accept Invitation Page — `/invite/:token`
 *
 * Where a newly created admin sets their own password (migration 058). The
 * only screen in the panel reachable with no account at all, and the reason
 * the route sits outside `ProtectedRoute`.
 *
 * It deliberately does **not** sign anybody in. Setting the password sends the
 * invitee to `/login` with their address filled in, and the ordinary first
 * sign-in is what puts them through Authenticator enrolment — a fresh account
 * has no second factor, so `AuthController::login` answers
 * `requiresTotpSetup`. Minting a session from a mail link would skip that gate
 * and would make the link worth more than it should ever be worth.
 */

import { FormEvent, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AuthCard } from '../components/auth/AuthCard'
import { Button } from '../components/common/Button'
import { Input } from '../components/common/Input'
import { getInvitations } from '../api/generated/invitations/invitations'
import { useApiError } from '../hooks/useApiError'
import { useLatestRequest } from '../hooks/useLatestRequest'
import { theme } from '../styles/design-system'

/**
 * The backend's rule, restated (`InvitationController::accept`): at least
 * eight characters, with a lower-case letter, an upper-case letter and a
 * digit.
 *
 * Checked here as well as there so somebody typing a password is told what is
 * wrong with it before a round trip, and never in a way that could *pass* here
 * and fail there — the server remains the authority, and its 422 is rendered
 * the same as any other failure below.
 */
const PASSWORD_RULE = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/

function Banner({ message, testId, tone }: { message: string; testId: string; tone: 'danger' | 'success' }) {
  const color = tone === 'danger' ? theme.colors.semantic.danger : theme.colors.semantic.success

  return (
    <div
      data-testid={testId}
      style={{
        background: `${color}20`,
        border: `1px solid ${color}`,
        borderRadius: theme.borderRadius.md,
        padding: theme.spacing.md,
        fontSize: theme.typography.fontSize.sm,
        color,
        marginBottom: theme.spacing.lg,
      }}
    >
      {message}
    </div>
  )
}

export function AcceptInvitationPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { token = '' } = useParams<{ token: string }>()
  const { apiErrorMessage } = useApiError()
  const latest = useLatestRequest()

  const [invitee, setInvitee] = useState<{ email: string; display_name: string } | null>(null)
  const [loading, setLoading] = useState(true)
  // Set when the *link* is unusable — expired, spent, unknown. Terminal: there
  // is no form to show and nothing the invitee can do here but ask for a new
  // invitation, so it replaces the form rather than sitting above it.
  const [linkError, setLinkError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [validation, setValidation] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)
  const [done, setDone] = useState(false)

  useEffect(() => {
    const signal = latest.next()

    getInvitations()
      .getInvitation(token, { signal })
      .then((result) => {
        if (signal.aborted) return
        // The generated types make every property optional (OpenAPI without
        // `required`), so a payload missing either field is treated as an
        // unusable link rather than rendered with a blank greeting.
        const invitation = result.invitation
        if (!invitation?.email || !invitation.display_name) {
          setLinkError(t('invite.errors.invalidLink'))
          return
        }
        setInvitee({ email: invitation.email, display_name: invitation.display_name })
      })
      .catch((err: unknown) => {
        if (signal.aborted) return
        setLinkError(apiErrorMessage(err, t('invite.errors.invalidLink')))
      })
      .finally(() => {
        if (!signal.aborted) setLoading(false)
      })
  }, [token]) // eslint-disable-line react-hooks/exhaustive-deps

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setFormError(null)

    const errors: Record<string, string> = {}
    if (!PASSWORD_RULE.test(password)) {
      errors.password = t('invite.validation.password')
    }
    if (password !== confirmation) {
      errors.confirmation = t('invite.validation.mismatch')
    }
    setValidation(errors)
    if (Object.keys(errors).length > 0) return

    setSubmitting(true)
    try {
      const result = await getInvitations().acceptInvitation(token, {
        password,
        password_confirmation: confirmation,
      })
      setDone(true)
      // The address is carried to the login form rather than asked for again:
      // the invitee has only ever seen it in an email, and it is the account's
      // identifier, not something they chose.
      navigate('/login', { replace: true, state: { email: result.email ?? '', invitationAccepted: true } })
    } catch (err: unknown) {
      setFormError(apiErrorMessage(err, t('invite.errors.acceptFailed')))
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <AuthCard title={t('invite.title')} subtitle={t('common.loading')}>
        <div data-testid="invite-loading" style={{ textAlign: 'center', color: theme.colors.text.secondary }}>
          {t('common.loading')}
        </div>
      </AuthCard>
    )
  }

  if (linkError || !invitee) {
    return (
      <AuthCard title={t('invite.title')} subtitle={t('invite.invalidSubtitle')}>
        <Banner message={linkError ?? t('invite.errors.invalidLink')} testId="invite-link-error" tone="danger" />
        <Button
          type="button"
          onClick={() => navigate('/login')}
          style={{ width: '100%' }}
          data-testid="invite-to-login-button"
        >
          {t('invite.goToLogin')}
        </Button>
      </AuthCard>
    )
  }

  return (
    <AuthCard title={t('invite.title')} subtitle={t('invite.subtitle', { name: invitee.display_name })}>
      {formError && <Banner message={formError} testId="invite-error" tone="danger" />}
      {done && <Banner message={t('invite.done')} testId="invite-done" tone="success" />}

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
        <Input
          data-testid="invite-email"
          label={t('invite.emailLabel')}
          type="email"
          value={invitee.email}
          readOnly
          disabled
        />
        <Input
          data-testid="invite-password-input"
          label={t('invite.passwordLabel')}
          type="password"
          autoComplete="new-password"
          value={password}
          error={validation.password}
          helpText={t('invite.passwordHint')}
          onChange={(e) => setPassword(e.target.value)}
          disabled={submitting}
          autoFocus
        />
        <Input
          data-testid="invite-password-confirmation-input"
          label={t('invite.confirmationLabel')}
          type="password"
          autoComplete="new-password"
          value={confirmation}
          error={validation.confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
          disabled={submitting}
        />
        <Button
          type="submit"
          disabled={submitting || password === '' || confirmation === ''}
          loading={submitting}
          style={{ width: '100%' }}
          data-testid="invite-submit-button"
        >
          {submitting ? t('invite.submitting') : t('invite.submit')}
        </Button>
      </form>

      {/*
        Said before it happens, not after. An admin who does not expect the
        authenticator step reads it as an obstacle between them and the panel
        rather than as the second half of getting in.
      */}
      <p
        data-testid="invite-next-step"
        style={{
          marginTop: theme.spacing.lg,
          marginBottom: 0,
          fontSize: theme.typography.fontSize.sm,
          color: theme.colors.text.secondary,
        }}
      >
        {t('invite.nextStep')}
      </p>
    </AuthCard>
  )
}
