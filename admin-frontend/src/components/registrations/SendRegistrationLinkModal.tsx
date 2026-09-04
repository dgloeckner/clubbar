/**
 * Send the club's Anmeldelink to somebody thinking of joining (#821, UC-A70).
 *
 * The one **outbound** control on an inbox. Everything else on `/registrations`
 * is about a submission that already arrived; this reaches outside the club and
 * offers somebody a way in.
 *
 * ## Why the double click is guarded here and nowhere else
 *
 * The backend deliberately does **not** deduplicate: `dedup_key` carries a
 * per-send nonce, so two sends to one address are two messages (ADR-0053
 * decision 4). That is right — a re-send is what answers *"I never got it"* —
 * and it means the only thing standing between an impatient double click and
 * two identical mails in a stranger's inbox is this component. So the button
 * disables itself for the duration of the request, and the form is not
 * re-armed until the admin edits the address or closes the dialog.
 *
 * ## What success says
 *
 * The confirmation names the address, because the admin typed it and a typo is
 * the failure mode that matters: nothing verifies the recipient, nothing
 * bounces back into this screen, and a link sent to `@exmaple.org` simply never
 * arrives. It also says the message is *queued* rather than sent — the drain
 * is what delivers, on its next tick (ADR-0038 rule 3) — because an admin who
 * reads "sent" tells the person waiting something that is not yet true.
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { getRegistrationReview } from '../../api/generated/registration-review/registration-review'
import { FieldError, ModalError, modalInputStyle } from '../modals/ModalError'
import { useApiError } from '../../hooks/useApiError'
import { useModalDialog } from '../../hooks/useModalDialog'
import { theme } from '../../styles/design-system'

export interface SendRegistrationLinkModalProps {
  isOpen: boolean
  onClose: () => void
}

/**
 * Enough to catch a fat-fingered address before a request goes out; the
 * backend's `email` rule is the one that decides. Deliberately not a clever
 * pattern — an over-strict one refuses real addresses, and the cost of letting
 * a bad one through is a 422 this dialog already renders.
 */
function looksLikeAnAddress(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())
}

export function SendRegistrationLinkModal({ isOpen, onClose }: SendRegistrationLinkModalProps) {
  const { t } = useTranslation()
  const { apiErrorMessage } = useApiError()
  const contentRef = useModalDialog(isOpen, onClose)

  const [email, setEmail] = useState('')
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldError, setFieldError] = useState<string | null>(null)
  /** The address the last successful send went to — the confirmation names it. */
  const [sentTo, setSentTo] = useState<string | null>(null)

  // The modal stays mounted while closed, so a stale confirmation or complaint
  // would otherwise greet whoever opens it next.
  useEffect(() => {
    if (!isOpen) {
      setEmail('')
      setSending(false)
      setError(null)
      setFieldError(null)
      setSentTo(null)
    }
  }, [isOpen])

  if (!isOpen) {
    return null
  }

  const handleChange = (value: string) => {
    setEmail(value)
    setFieldError(null)
    setError(null)
    // Editing the address re-arms the form: this is the deliberate second send,
    // to a corrected address, and it must not be blocked by the first one's
    // confirmation still being on screen.
    setSentTo(null)
  }

  const handleSubmit = async () => {
    // The guard, in one line: a second click while the first request is in
    // flight, or while its confirmation still stands, is the accident.
    if (sending || sentTo !== null) {
      return
    }

    const address = email.trim()
    if (!looksLikeAnAddress(address)) {
      setFieldError(t('registrations.sendLink.invalidEmail'))
      return
    }

    setSending(true)
    setError(null)

    try {
      await getRegistrationReview().sendRegistrationLink({ email: address })
      setSentTo(address)
    } catch (err) {
      // The refusals this can carry are the availability switch's own —
      // `registration_disabled`, `registration_no_secret`, `document_url_missing`
      // — and `useApiError` turns each into the admin's language. Never
      // `err.response.data.message`, which the backend always writes in English.
      setError(apiErrorMessage(err, t('registrations.sendLink.failed')))
    } finally {
      setSending(false)
    }
  }

  return (
    <div
      data-testid="send-registration-link-modal"
      style={{
        position: 'fixed',
        inset: 0,
        background: theme.overlay.backdrop,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1100,
      }}
    >
      <div
        ref={contentRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="send-registration-link-title"
        tabIndex={-1}
        style={{
          background: theme.colors.bg.primary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '460px',
          width: '90%',
        }}
      >
        <h2
          id="send-registration-link-title"
          style={{ margin: 0, marginBottom: theme.spacing.sm }}
        >
          {t('registrations.sendLink.title')}
        </h2>

        <p
          style={{
            margin: `0 0 ${theme.spacing.lg} 0`,
            fontSize: theme.typography.fontSize.sm,
            color: theme.colors.text.secondary,
          }}
        >
          {t('registrations.sendLink.body')}
        </p>

        <ModalError message={error} testId="send-registration-link-error" />

        {sentTo !== null && (
          <div
            data-testid="send-registration-link-success"
            role="status"
            style={{
              marginBottom: theme.spacing.lg,
              padding: theme.spacing.md,
              background: theme.badges.success.bg,
              borderLeft: `3px solid ${theme.badges.success.dot}`,
              borderRadius: theme.borderRadius.md,
              color: theme.badges.success.text,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            {t('registrations.sendLink.queued', { email: sentTo })}
          </div>
        )}

        <label
          htmlFor="send-registration-link-email"
          style={{
            display: 'block',
            marginBottom: theme.spacing.xs,
            fontSize: theme.typography.fontSize.sm,
            fontWeight: 600,
            color: theme.colors.text.primary,
          }}
        >
          {t('registrations.sendLink.emailLabel')}
        </label>

        <input
          id="send-registration-link-email"
          data-testid="send-registration-link-email"
          type="email"
          autoComplete="off"
          value={email}
          onChange={(event) => handleChange(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') void handleSubmit()
          }}
          placeholder={t('registrations.sendLink.emailPlaceholder')}
          style={modalInputStyle(!!fieldError)}
        />
        <FieldError message={fieldError} testId="send-registration-link-email-error" />

        <div style={{ display: 'flex', gap: theme.spacing.md, marginTop: theme.spacing.md }}>
          <button
            type="button"
            data-testid="send-registration-link-confirm"
            onClick={() => void handleSubmit()}
            disabled={sending || sentTo !== null}
            style={{
              flex: 1,
              padding: theme.spacing.md,
              background:
                sending || sentTo !== null ? theme.colors.bg.tertiary : theme.colors.semantic.primary,
              color: sending || sentTo !== null ? theme.colors.text.secondary : 'white',
              border: 'none',
              borderRadius: theme.borderRadius.md,
              cursor: sending || sentTo !== null ? 'not-allowed' : 'pointer',
              minHeight: 44,
            }}
          >
            {sending ? t('registrations.sendLink.sending') : t('registrations.sendLink.send')}
          </button>
          <button
            type="button"
            data-testid="send-registration-link-cancel"
            onClick={onClose}
            style={{
              flex: 1,
              padding: theme.spacing.md,
              background: 'transparent',
              color: theme.colors.text.secondary,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              cursor: 'pointer',
              minHeight: 44,
            }}
          >
            {sentTo !== null ? t('common.close') : t('common.cancel')}
          </button>
        </div>
      </div>
    </div>
  )
}
