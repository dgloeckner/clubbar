/**
 * Accept Invitation Page — `/invite#<token>`
 *
 * Where a newly created admin sets their own password (migration 058). The
 * only screen in the panel reachable with no account at all, and the reason
 * the route sits outside `ProtectedRoute`.
 *
 * **The token is in the fragment, and this page is the only thing that reads
 * it.** A fragment never leaves the browser — it is stripped before the request
 * goes out, so it reaches no access log, no proxy log and no `Referer` header —
 * which is why the link is shaped this way rather than as `/invite/<token>`.
 * From here the token travels in a request body (`POST /api/invitations/lookup`
 * and `.../accept`), for the same reason: bodies are not logged, request lines
 * are.
 *
 * The fragment is deliberately **not** cleared once read. Stripping it would
 * hide the token from the address bar and break reloading the page for the one
 * person legitimately holding it, in exchange for nothing: what remains is a
 * single-use token in the invitee's own history, on the invitee's own machine.
 *
 * It deliberately does **not** sign anybody in. Setting the password sends the
 * invitee to `/login` with their address filled in, and the ordinary first
 * sign-in is what puts them through Authenticator enrolment — a fresh account
 * has no second factor, so `AuthController::login` answers
 * `requiresTotpSetup`. Minting a session from a mail link would skip that gate
 * and would make the link worth more than it should ever be worth.
 */

import { FormEvent, useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AuthCard } from '../components/auth/AuthCard'
import { Button } from '../components/common/Button'
import { Input } from '../components/common/Input'
import { Badge, type BadgeProps } from '../components/common/Badge'
import type { AdminRole } from '../api/generated/adminRole'
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

/**
 * Role names and their colours, matching the admin list so one account looks
 * the same wherever it is shown. Untranslated in both locales: `Kassenwart`
 * and `Getränkewart` are Vereinsämter rather than concepts with an English
 * equivalent, the precedent CONTEXT.md sets for `Storno` and `Deckel`.
 */
const ROLE_LABEL: Record<AdminRole, string> = {
  admin: 'Admin',
  kassenwart: 'Kassenwart',
  getraenkewart: 'Getränkewart',
}

const ROLE_BADGE_VARIANT: Record<AdminRole, BadgeProps['variant']> = {
  admin: 'danger',
  kassenwart: 'info',
  getraenkewart: 'success',
}

const labelStyle = {
  display: 'block',
  fontSize: theme.typography.fontSize.sm,
  fontWeight: theme.typography.fontWeight.semibold,
  color: theme.colors.text.primary,
} as const

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
  // `#` and everything after it, as the router reports it. Read rather than
  // routed on: the fragment is not part of any path pattern, and it is not sent
  // to the server that serves this page either.
  const { hash } = useLocation()
  const token = hash.startsWith('#') ? decodeURIComponent(hash.slice(1)) : ''
  const { apiErrorMessage } = useApiError()
  const latest = useLatestRequest()

  const [invitee, setInvitee] = useState<{
    email: string
    display_name: string
    roles: AdminRole[]
  } | null>(null)
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
      .lookupInvitation({ token }, { signal })
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
        setInvitee({
          email: invitation.email,
          display_name: invitation.display_name,
          roles: invitation.roles ?? [],
        })
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
      const result = await getInvitations().acceptInvitation({
        token,
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
        {/*
          What they are being onboarded *as*, before they are asked to set a
          credential. Without it, a Getränkewart is told only that an account
          was created for them and has to sign in and infer their job from
          which pages happen to open. Role names are shown verbatim in both
          locales — Vereinsämter, like Storno and Deckel.
        */}
        {invitee.roles.length > 0 && (
          <div>
            <span style={labelStyle}>{t('invite.roleLabel')}</span>
            <div
              data-testid="invite-roles"
              style={{
                display: 'flex',
                flexWrap: 'wrap',
                gap: theme.spacing.xs,
                marginTop: theme.spacing.xs,
              }}
            >
              {invitee.roles.map((role) => (
                <Badge
                  key={role}
                  label={ROLE_LABEL[role] ?? role}
                  variant={ROLE_BADGE_VARIANT[role] ?? 'info'}
                  showDot={false}
                  testId={`invite-role-${role}`}
                />
              ))}
            </div>
          </div>
        )}
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
