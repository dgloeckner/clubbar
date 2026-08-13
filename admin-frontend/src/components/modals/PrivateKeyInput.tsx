/**
 * The club's archived private key, collected for the length of one request.
 *
 * IBANs are sealed at rest and the server holds no private key (ADR-0036), so
 * building a SEPA file means handing the key over for that one export. It lives
 * offline — on paper, or on a stick in the club's safe — which is why both ways
 * in are offered: pick the key file, or paste what is written on the sheet.
 *
 * The value never leaves this component except through `onChange`; nothing is
 * persisted, and the field is cleared whenever the dialog reopens.
 *
 * Test IDs: `private-key-file`, `private-key-paste`, `private-key-error`.
 */

import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { modalInputStyle } from './ModalError'

export interface PrivateKeyInputProps {
  /** The key as base64, or '' when nothing usable has been supplied yet. */
  value: string
  onChange: (privateKeyBase64: string) => void
}

/** 32 raw bytes, base64-encoded — what tools/keypair-generator.html emits. */
const BASE64_KEY = /^[A-Za-z0-9+/]{43}=$/

export function PrivateKeyInput({ value, onChange }: PrivateKeyInputProps) {
  const { t } = useTranslation()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [fileError, setFileError] = useState<string | null>(null)

  const handleFile = async (file: File | undefined) => {
    if (!file) return
    setFileError(null)
    try {
      // The generator writes the base64 key as the file's only content; trim
      // covers the trailing newline a text editor adds.
      const text = (await file.text()).trim()
      if (!BASE64_KEY.test(text)) {
        setFileError(t('settlements.export.keyFileInvalid'))
        onChange('')
        return
      }
      onChange(text)
    } catch {
      setFileError(t('settlements.export.keyFileUnreadable'))
      onChange('')
    }
  }

  return (
    <div style={{ marginBottom: theme.spacing.md }}>
      <p
        style={{
          margin: `0 0 ${theme.spacing.sm} 0`,
          fontSize: theme.typography.fontSize.xs,
          color: theme.colors.text.secondary,
        }}
      >
        {t('settlements.export.keyHint')}
      </p>

      <input
        ref={fileInputRef}
        data-testid="private-key-file"
        type="file"
        accept=".key,.txt,text/plain"
        onChange={(e) => void handleFile(e.target.files?.[0])}
        style={{
          display: 'block',
          width: '100%',
          marginBottom: theme.spacing.sm,
          fontSize: theme.typography.fontSize.sm,
          color: theme.colors.text.secondary,
        }}
      />

      <input
        data-testid="private-key-paste"
        type="password"
        autoComplete="off"
        spellCheck={false}
        placeholder={t('settlements.export.keyPastePlaceholder')}
        value={value}
        onChange={(e) => {
          setFileError(null)
          onChange(e.target.value.trim())
        }}
        style={{ ...modalInputStyle(!!fileError), fontFamily: 'monospace' }}
      />

      {fileError && (
        <p
          data-testid="private-key-error"
          style={{
            margin: `${theme.spacing.xs} 0 0 0`,
            fontSize: theme.typography.fontSize.sm,
            color: theme.colors.semantic.danger,
          }}
        >
          {fileError}
        </p>
      )}
    </div>
  )
}
