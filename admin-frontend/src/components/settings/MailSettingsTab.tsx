/**
 * Mail settings (#407, ADR-0038).
 *
 * Two halves of one question — *"will announcements actually go out?"* — and
 * only one of them is editable here:
 *
 * | Editable | Where it lives |
 * |---|---|
 * | Sender, reply-to, header variant, footer | This form; club data |
 * | Scheduler interval, drain batch size, run budget | This form; operational dials, no secret in any of them |
 * | **The URL-trigger secret** (`/api/cron/drain`) | Its own rotate action below (#473) — a credential, not a form field |
 * | **The SMTP DSN** | `config.php`, next to the database password (ADR-0031 decision 2) |
 *
 * The DSN is shown as a **measured, read-only** state, never as a field. It
 * carries a password, so changing the mail server is an installer or file
 * operation — and an admin who cannot find that setting would otherwise look
 * for it in exactly this form, which is why the transport panel says where it
 * is instead of leaving a gap.
 *
 * The cron secret sits between those two: unlike the DSN it *is* editable from
 * here, but unlike every other field it is never round-tripped through
 * `form`/PATCH — only a hash is ever persisted, so rotating mints a fresh one
 * (step-up gated, same as a terminal token) and shows it exactly once. Once
 * rotated it supersedes `config.php`'s `cron.secret`, if this installation had
 * one, rather than leaving two live credentials.
 *
 * Self-contained rather than driven by `SettingsPage`'s state, following
 * `SecurityCheckTab` in this same directory: this tab owns a form, a measured
 * status panel and an action with its own result, and threading all three
 * through a page that is already a thousand lines would buy nothing. The one
 * thing it still takes from the caller is `callerTotpEnabled` (#337) — the
 * step-up dialog needs to know that before it can ask for a code.
 *
 * Test IDs: `settings-mail-*`.
 */

import { useCallback, useEffect, useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import { theme, formatDateTime } from '../../styles/design-system'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { getMailSettings } from '../../api/generated/mail-settings/mail-settings'
import type { MailConfig, MailConfigUpdateRequest, TestMailResult } from '../../api/generated'
import { StepUpConfirmDialog, type StepUpCredentials } from '../modals/StepUpConfirmDialog'
import { SecretDisplayModal } from '../modals/SecretDisplayModal'

const HEADER_STYLES = ['red', 'petrol', 'paper'] as const
/**
 * Fastest first, and `fifteen_minutes` is the one to pick if the host allows it
 * (#473): it is what `bin/cron.php` recommends, and until it existed such an
 * installation had to declare `hourly` — safe, but describing a machine four
 * times slower than the real one. `weekly` is absent because it is refused; the
 * API answers a submitted one with a paragraph of reasoning rather than "must
 * be one of".
 */
const CRON_INTERVALS = ['fifteen_minutes', 'hourly', 'daily'] as const

/**
 * The Deckelauszug's cadence (ADR-0039 decision 3).
 *
 * `off` is a real value rather than a way of clearing the field: it is the
 * club's only control over this feature, because a system with no member login
 * has nowhere to put a per-member opt-out. `weekly` is deliberately not an
 * option — the enum has no such case, for a gentler reason than the scheduler's
 * refusal of the same word: nothing breaks, a statement every week simply stops
 * being predictable and starts being relentless.
 */
const STATEMENT_CADENCES = ['off', 'monthly', 'quarterly'] as const

/**
 * The near-limit digest's cadence (ADR-0047, migration 053).
 *
 * `weekly` is here and deliberately absent above, and the difference is the
 * audience: a Deckelauszug goes to the whole membership, this goes to the
 * handful of people who run the club, about a condition that changes inside a
 * week.
 */
const DIGEST_CADENCES = ['off', 'daily', 'weekly', 'monthly'] as const

type FormState = {
  sender_name: string
  sender_address: string
  reply_to_address: string
  header_style: string
  footer_org_name: string
  footer_address_line: string
  website_url: string
  logo_url: string
  cron_interval: string
  drain_batch_size: string
  drain_budget_seconds: string
  statement_cadence: string
  credit_limit_digest_cadence: string
}

function toForm(config: MailConfig): FormState {
  return {
    sender_name: config.sender_name ?? '',
    sender_address: config.sender_address ?? '',
    reply_to_address: config.reply_to_address ?? '',
    header_style: config.header_style ?? 'paper',
    footer_org_name: config.footer_org_name ?? '',
    footer_address_line: config.footer_address_line ?? '',
    website_url: config.website_url ?? '',
    logo_url: config.logo_url ?? '',
    cron_interval: config.cron_interval ?? 'hourly',
    drain_batch_size: String(config.drain_batch_size ?? 100),
    drain_budget_seconds: String(config.drain_budget_seconds ?? 25),
    statement_cadence: config.statement_cadence ?? 'off',
    credit_limit_digest_cadence: config.credit_limit_digest_cadence ?? 'off',
  }
}

export interface MailSettingsTabProps {
  /** Whether the *caller* has 2FA enabled — decides whether rotating the cron secret asks for a TOTP code too (#337, #473). */
  callerTotpEnabled: boolean
}

export function MailSettingsTab({ callerTotpEnabled }: MailSettingsTabProps) {
  const { t } = useTranslation()
  const request = useLatestRequest()

  const [config, setConfig] = useState<MailConfig | null>(null)
  const [form, setForm] = useState<FormState | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [success, setSuccess] = useState<string | null>(null)
  const [testing, setTesting] = useState(false)
  const [testResult, setTestResult] = useState<TestMailResult | null>(null)

  // Rotating the URL-trigger secret (#473) — a credential, not a form field,
  // so it has its own step-up dialog and one-time display rather than living
  // in `form`/`save()`.
  const [rotateConfirmOpen, setRotateConfirmOpen] = useState(false)
  const [rotating, setRotating] = useState(false)
  const [rotateError, setRotateError] = useState<string | null>(null)
  const [issuedSecret, setIssuedSecret] = useState<string | null>(null)

  const load = useCallback(async () => {
    const signal = request.next()
    setLoading(true)

    try {
      const result = await getMailSettings().getMailConfig({ signal })
      if (signal.aborted) return
      setConfig(result)
      setForm(toForm(result))
      setError(null)
    } catch (err: unknown) {
      if (signal.aborted) return
      setError(parseError(err, t('settings.mail.errors.load')))
    } finally {
      if (!signal.aborted) setLoading(false)
    }
  }, [request, t])

  useEffect(() => {
    void load()
  }, [load])

  const save = async () => {
    if (!form) return

    setSaving(true)
    setSuccess(null)
    setError(null)
    setFieldErrors({})

    const payload: MailConfigUpdateRequest = {
      sender_name: form.sender_name,
      sender_address: form.sender_address,
      // An empty optional field means "unset", which the API spells as null.
      reply_to_address: form.reply_to_address || null,
      header_style: form.header_style as MailConfigUpdateRequest['header_style'],
      footer_org_name: form.footer_org_name,
      footer_address_line: form.footer_address_line || null,
      website_url: form.website_url || null,
      logo_url: form.logo_url || null,
      cron_interval: form.cron_interval as MailConfigUpdateRequest['cron_interval'],
      drain_batch_size: Number(form.drain_batch_size),
      drain_budget_seconds: Number(form.drain_budget_seconds),
      statement_cadence: form.statement_cadence as MailConfigUpdateRequest['statement_cadence'],
      credit_limit_digest_cadence: form.credit_limit_digest_cadence as MailConfigUpdateRequest['credit_limit_digest_cadence'],
    }

    try {
      const updated = await getMailSettings().updateMailConfig(payload)
      setConfig(updated)
      setForm(toForm(updated))
      setSuccess(t('settings.mail.saved'))
    } catch (err: unknown) {
      // A 422 names the field. The one that matters is `cron_interval`, whose
      // message explains why a weekly scheduler is refused — it is a paragraph
      // of reasoning, not a validation grumble, and it belongs next to the
      // field the admin just changed.
      const messages = axios.isAxiosError(err) ? err.response?.data?.messages : undefined
      if (messages && typeof messages === 'object') {
        setFieldErrors(messages as Record<string, string>)
      } else {
        setError(parseError(err, t('settings.mail.errors.save')))
      }
    } finally {
      setSaving(false)
    }
  }

  const sendTestMail = async () => {
    setTesting(true)
    setTestResult(null)

    try {
      setTestResult(await getMailSettings().sendTestMail())
    } catch (err: unknown) {
      setTestResult({ sent: false, error: parseError(err, t('settings.mail.errors.test')) })
    } finally {
      setTesting(false)
    }
  }

  const rotateCronSecret = async (credentials: StepUpCredentials) => {
    setRotating(true)
    try {
      const result = await getMailSettings().rotateCronSecret(credentials)
      setConfig(result)
      setForm(toForm(result))
      setRotateConfirmOpen(false)
      setRotateError(null)
      // Shown exactly once — only the hash is persisted, so a secret closed
      // away unread has to be rotated again to be seen at all.
      setIssuedSecret(result.cron_secret ?? null)
    } catch (err: unknown) {
      setRotateError(parseError(err, t('settings.mail.errors.rotateCronSecret')))
    } finally {
      setRotating(false)
    }
  }

  if (loading || !form) {
    return (
      <div data-testid="settings-mail-loading" style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        {t('common.loading')}
      </div>
    )
  }

  const field = (
    name: keyof FormState,
    label: string,
    options?: { type?: string; hint?: string; placeholder?: string }
  ) => (
    <div style={fieldWrapperStyle}>
      <label style={labelStyle} htmlFor={`settings-mail-${name}`}>
        {label}
      </label>
      <input
        id={`settings-mail-${name}`}
        data-testid={`settings-mail-${name}`}
        type={options?.type ?? 'text'}
        value={form[name]}
        placeholder={options?.placeholder}
        onChange={(e) => setForm({ ...form, [name]: e.target.value })}
        style={{ ...inputStyle, borderColor: fieldErrors[name] ? theme.colors.semantic.danger : theme.colors.border.light }}
      />
      {options?.hint && !fieldErrors[name] && <div style={hintStyle}>{options.hint}</div>}
      {fieldErrors[name] && (
        <div data-testid={`settings-mail-error-${name}`} style={fieldErrorStyle}>
          {fieldErrors[name]}
        </div>
      )}
    </div>
  )

  return (
    <div data-testid="settings-mail-tab">
      {/* What the *transport* is doing — measured, and not editable here. */}
      <div data-testid="settings-mail-transport" style={transportPanelStyle(config)}>
        <div style={{ fontWeight: theme.typography.fontWeight.semibold, marginBottom: theme.spacing.xs }}>
          {t('settings.mail.transportTitle')}
        </div>
        <div data-testid="settings-mail-transport-state" style={{ fontSize: theme.typography.fontSize.sm }}>
          {config?.transport?.configured
            ? (config.transport.summary ?? t('settings.mail.transportConfigured'))
            : t('settings.mail.transportMissing')}
        </div>
        {config?.transport?.error && (
          <div data-testid="settings-mail-transport-error" style={fieldErrorStyle}>
            {config.transport.error}
          </div>
        )}
        <div style={hintStyle}>{t('settings.mail.transportHint')}</div>

        <button
          type="button"
          data-testid="settings-mail-test-button"
          onClick={sendTestMail}
          disabled={testing}
          style={secondaryButtonStyle}
        >
          {testing ? t('common.loading') : t('settings.mail.sendTest')}
        </button>

        {testResult && (
          <div
            data-testid="settings-mail-test-result"
            data-sent={testResult.sent ? 'true' : 'false'}
            style={{
              ...noticeStyle,
              color: testResult.sent ? theme.colors.semantic.success : theme.colors.semantic.danger,
            }}
          >
            {testResult.sent
              ? t('settings.mail.testSent', { recipient: testResult.recipient ?? '' })
              : (testResult.error ?? t('settings.mail.errors.test'))}
          </div>
        )}
      </div>

      {/* The URL-trigger secret (#473). A credential, not a form field: shown
          only as configured/not, rotated through its own step-up action, and
          the plaintext appears exactly once, in the modal below. */}
      <div data-testid="settings-mail-cron-secret" style={transportPanelStyle(config)}>
        <div style={{ fontWeight: theme.typography.fontWeight.semibold, marginBottom: theme.spacing.xs }}>
          {t('settings.mail.cronSecretTitle')}
        </div>
        <div data-testid="settings-mail-cron-secret-state" style={{ fontSize: theme.typography.fontSize.sm }}>
          {config?.cron_secret_configured
            ? t('settings.mail.cronSecretConfigured')
            : t('settings.mail.cronSecretNotConfigured')}
        </div>
        <div style={hintStyle}>
          {config?.cron_secret_rotated_at
            ? t('settings.mail.cronSecretRotatedAt', { date: formatDateTime(config.cron_secret_rotated_at) })
            : t('settings.mail.cronSecretNeverRotated')}
        </div>
        <div style={hintStyle}>{t('settings.mail.cronSecretHint')}</div>

        <button
          type="button"
          data-testid="settings-mail-cron-secret-rotate"
          onClick={() => {
            setRotateError(null)
            setRotateConfirmOpen(true)
          }}
          disabled={rotating}
          style={secondaryButtonStyle}
        >
          {config?.cron_secret_configured
            ? t('settings.mail.rotateCronSecret')
            : t('settings.mail.generateCronSecret')}
        </button>
      </div>

      {error && (
        <div data-testid="settings-mail-banner-error" style={{ ...noticeStyle, color: theme.colors.semantic.danger }}>
          {error}
        </div>
      )}

      {success && (
        <div data-testid="settings-mail-success" style={{ ...noticeStyle, color: theme.colors.semantic.success }}>
          {success}
        </div>
      )}

      <form
        data-testid="settings-mail-form"
        onSubmit={(e) => {
          e.preventDefault()
          void save()
        }}
        style={{ display: 'flex', flexDirection: 'column', maxWidth: 520 }}
      >
        {field('sender_name', t('settings.mail.senderName'))}
        {field('sender_address', t('settings.mail.senderAddress'), {
          type: 'email',
          hint: t('settings.mail.senderAddressHint'),
        })}
        {field('reply_to_address', t('settings.mail.replyTo'), {
          type: 'email',
          hint: t('settings.mail.replyToHint'),
        })}

        <div style={fieldWrapperStyle}>
          <label style={labelStyle} htmlFor="settings-mail-header_style">
            {t('settings.mail.headerStyle')}
          </label>
          <select
            id="settings-mail-header_style"
            data-testid="settings-mail-header_style"
            value={form.header_style}
            onChange={(e) => setForm({ ...form, header_style: e.target.value })}
            style={inputStyle}
          >
            {HEADER_STYLES.map((style) => (
              <option key={style} value={style}>
                {t(`settings.mail.headerStyles.${style}`)}
              </option>
            ))}
          </select>
        </div>

        {field('footer_org_name', t('settings.mail.footerOrgName'))}
        {field('footer_address_line', t('settings.mail.footerAddress'))}
        {field('website_url', t('settings.mail.websiteUrl'), { placeholder: 'https://…' })}
        {field('logo_url', t('settings.mail.logoUrl'), { hint: t('settings.mail.logoUrlHint') })}

        {/* The scheduler dials. Not branding, but the same rule applies: no
            secret in either, so the treasurer should not need a file editor. */}
        <div style={fieldWrapperStyle}>
          <label style={labelStyle} htmlFor="settings-mail-cron_interval">
            {t('settings.mail.cronInterval')}
          </label>
          <select
            id="settings-mail-cron_interval"
            data-testid="settings-mail-cron_interval"
            value={form.cron_interval}
            onChange={(e) => setForm({ ...form, cron_interval: e.target.value })}
            style={{
              ...inputStyle,
              borderColor: fieldErrors.cron_interval ? theme.colors.semantic.danger : theme.colors.border.light,
            }}
          >
            {CRON_INTERVALS.map((interval) => (
              <option key={interval} value={interval}>
                {t(`settings.mail.cronIntervals.${interval}`)}
              </option>
            ))}
          </select>
          <div style={hintStyle}>{t('settings.mail.cronIntervalHint')}</div>
          {fieldErrors.cron_interval && (
            <div data-testid="settings-mail-error-cron_interval" style={fieldErrorStyle}>
              {fieldErrors.cron_interval}
            </div>
          )}
        </div>

        {field('drain_batch_size', t('settings.mail.batchSize'), {
          type: 'number',
          hint: t('settings.mail.batchSizeHint'),
        })}

        {field('drain_budget_seconds', t('settings.mail.budgetSeconds'), {
          type: 'number',
          hint: t('settings.mail.budgetSecondsHint'),
        })}

        {/* Not a scheduler dial: this one decides whether the whole membership
            receives mail it did not ask for, so it sits below them with its own
            explanation rather than beside the batch size. */}
        <div style={fieldWrapperStyle}>
          <label style={labelStyle} htmlFor="settings-mail-statement_cadence">
            {t('settings.mail.statementCadence')}
          </label>
          <select
            id="settings-mail-statement_cadence"
            data-testid="settings-mail-statement_cadence"
            value={form.statement_cadence}
            onChange={(e) => setForm({ ...form, statement_cadence: e.target.value })}
            style={{
              ...inputStyle,
              borderColor: fieldErrors.statement_cadence
                ? theme.colors.semantic.danger
                : theme.colors.border.light,
            }}
          >
            {STATEMENT_CADENCES.map((cadence) => (
              <option key={cadence} value={cadence}>
                {t(`settings.mail.statementCadences.${cadence}`)}
              </option>
            ))}
          </select>
          <div style={hintStyle}>{t('settings.mail.statementCadenceHint')}</div>
          {fieldErrors.statement_cadence && (
            <div data-testid="settings-mail-error-statement_cadence" style={fieldErrorStyle}>
              {fieldErrors.statement_cadence}
            </div>
          )}
        </div>

        {/* The other push notification, and the other one that is not a
            scheduler dial: who hears about members nearing their Deckel limit,
            and how often. Beside the statement cadence because the two answer
            the same shape of question — how much mail does this club want —
            and nowhere near the batch size, which is about the queue. */}
        <div style={fieldWrapperStyle}>
          <label style={labelStyle} htmlFor="settings-mail-credit_limit_digest_cadence">
            {t('settings.mail.digestCadence')}
          </label>
          <select
            id="settings-mail-credit_limit_digest_cadence"
            data-testid="settings-mail-credit_limit_digest_cadence"
            value={form.credit_limit_digest_cadence}
            onChange={(e) => setForm({ ...form, credit_limit_digest_cadence: e.target.value })}
            style={{
              ...inputStyle,
              borderColor: fieldErrors.credit_limit_digest_cadence
                ? theme.colors.semantic.danger
                : theme.colors.border.light,
            }}
          >
            {DIGEST_CADENCES.map((cadence) => (
              <option key={cadence} value={cadence}>
                {t(`settings.mail.digestCadences.${cadence}`)}
              </option>
            ))}
          </select>
          <div style={hintStyle}>{t('settings.mail.digestCadenceHint')}</div>
          {fieldErrors.credit_limit_digest_cadence && (
            <div data-testid="settings-mail-error-credit_limit_digest_cadence" style={fieldErrorStyle}>
              {fieldErrors.credit_limit_digest_cadence}
            </div>
          )}
        </div>

        <div style={{ display: 'flex', gap: theme.spacing.md, marginTop: theme.spacing.lg }}>
          <button type="submit" data-testid="settings-mail-save" disabled={saving} style={primaryButtonStyle}>
            {saving ? t('common.loading') : t('common.save')}
          </button>
          <button
            type="button"
            data-testid="settings-mail-cancel"
            onClick={() => config && setForm(toForm(config))}
            disabled={saving}
            style={secondaryButtonStyle}
          >
            {t('common.cancel')}
          </button>
        </div>
      </form>

      <StepUpConfirmDialog
        isOpen={rotateConfirmOpen}
        title={config?.cron_secret_configured ? t('settings.mail.rotateCronSecret') : t('settings.mail.generateCronSecret')}
        message={t('settings.mail.rotateCronSecretConfirm')}
        confirmLabel={t('common.confirm')}
        requiresTotp={callerTotpEnabled}
        error={rotateError}
        confirmDisabled={rotating}
        onConfirm={rotateCronSecret}
        onCancel={() => {
          setRotateConfirmOpen(false)
          setRotateError(null)
        }}
      />

      <SecretDisplayModal
        isOpen={issuedSecret !== null}
        secret={issuedSecret}
        title={t('settings.mail.cronSecretGenerated')}
        warning={t('settings.mail.cronSecretWarning')}
        testIdPrefix="settings-mail-cron-secret-value"
        onClose={() => setIssuedSecret(null)}
      />
    </div>
  )
}

function parseError(err: unknown, fallback: string): string {
  if (axios.isAxiosError(err)) {
    return err.response?.data?.message ?? err.message
  }
  return err instanceof Error ? err.message : fallback
}

const fieldWrapperStyle: CSSProperties = { marginBottom: theme.spacing.lg }

const labelStyle: CSSProperties = {
  display: 'block',
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.secondary,
  marginBottom: theme.spacing.xs,
}

const inputStyle: CSSProperties = {
  width: '100%',
  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
  background: theme.colors.bg.input,
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.md,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
  boxSizing: 'border-box',
}

const hintStyle: CSSProperties = {
  marginTop: theme.spacing.xs,
  fontSize: theme.typography.fontSize.xs,
  color: theme.colors.text.muted,
}

const fieldErrorStyle: CSSProperties = {
  marginTop: theme.spacing.xs,
  fontSize: theme.typography.fontSize.xs,
  color: theme.colors.semantic.danger,
}

const noticeStyle: CSSProperties = {
  padding: theme.spacing.md,
  marginBottom: theme.spacing.lg,
  borderRadius: theme.borderRadius.md,
  border: `1px solid ${theme.colors.border.light}`,
  fontSize: theme.typography.fontSize.sm,
}

const transportPanelStyle = (config: MailConfig | null): CSSProperties => ({
  padding: theme.spacing.md,
  marginBottom: theme.spacing.lg,
  borderRadius: theme.borderRadius.md,
  border: `1px solid ${config?.can_send ? theme.colors.border.light : theme.colors.semantic.danger}`,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
  maxWidth: 520,
})

const primaryButtonStyle: CSSProperties = {
  padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
  background: theme.colors.semantic.primary,
  border: 'none',
  borderRadius: theme.borderRadius.md,
  color: '#fff',
  fontSize: theme.typography.fontSize.sm,
  cursor: 'pointer',
}

const secondaryButtonStyle: CSSProperties = {
  marginTop: theme.spacing.md,
  padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
  background: 'transparent',
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.md,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
  cursor: 'pointer',
}
