import React, { useRef, useState } from 'react'
import imageCompression from 'browser-image-compression'
import heic2any from 'heic2any'
import { getMandateDocument } from '../api/generated/mandate-document/mandate-document'
import type { ExtractionResult } from '../api/generated/extractionResult'
import { useTranslation } from 'react-i18next'
import { theme } from '../styles/design-system'

interface Props {
  onExtractionComplete?: (extraction: ExtractionResult) => void
}

type ComponentState = 'idle' | 'selected' | 'extracting'

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

// heic2any's decoder runs in a worker that needs `unsafe-eval` (Emscripten
// glue code) — the admin panel's Content-Security-Policy does not grant it
// (#250, ADR-0031 layer L2), so the conversion never resolves there. A hard
// timeout turns that into a clear message instead of a spinner that hangs
// forever; where the policy does allow eval (a deployment outside the
// shipped package) the real conversion still wins the race well within it.
const HEIC_CONVERSION_TIMEOUT_MS = 15_000

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('HEIC conversion timed out')), ms)
    promise.then(
      (value) => { clearTimeout(timer); resolve(value) },
      (err) => { clearTimeout(timer); reject(err) }
    )
  })
}

/**
 * Pick a mandate scan, extract its fields, hand them to the parent form.
 * Stateless end to end (ADR-0037): nothing is uploaded or stored here, and
 * there is nothing to download or delete afterwards.
 */
export function MandateDocumentSection({ onExtractionComplete }: Props) {
  const { t } = useTranslation()
  const inputRef = useRef<HTMLInputElement>(null)

  const [state, setState] = useState<ComponentState>('idle')
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [originalSize, setOriginalSize] = useState<number>(0)
  const [error, setError] = useState<string | null>(null)

  async function processFile(raw: File) {
    setError(null)
    setOriginalSize(raw.size)

    try {
      let processedFile: File = raw

      // Convert HEIC to JPEG first.
      // heic2any returns Blob | Blob[] — take first item when it returns an array
      // (burst/multi-image HEIC files).
      if (raw.type === 'image/heic' || raw.name.toLowerCase().endsWith('.heic')) {
        try {
          const result = await withTimeout(
            heic2any({ blob: raw, toType: 'image/jpeg', quality: 0.85 }),
            HEIC_CONVERSION_TIMEOUT_MS
          )
          const blob = Array.isArray(result) ? result[0] : result
          processedFile = new File(
            [blob],
            raw.name.replace(/\.heic$/i, '.jpg'),
            { type: 'image/jpeg' }
          )
        } catch {
          // The backend only accepts JPEG, PNG and PDF (never raw HEIC), so
          // there is nothing useful left to do with this file — surface the
          // specific, actionable message rather than the generic one below.
          setError(t('mandateDocument.heicConversionError'))
          if (inputRef.current) inputRef.current.value = ''
          return
        }
      }

      // Compress images (not PDFs)
      if (processedFile.type !== 'application/pdf') {
        const name = processedFile.name
        const compressed = await imageCompression(processedFile, {
          maxSizeMB: 2,
          maxWidthOrHeight: 2000,
          useWebWorker: true,
        })
        // browser-image-compression drops the filename; restore it
        processedFile = new File([compressed], name, { type: compressed.type })
      }

      setSelectedFile(processedFile)
      setState('selected')
    } catch (err) {
      setError(t('mandateDocument.processingError'))
    }

    // Reset input so the same file can be re-selected if cancelled
    if (inputRef.current) inputRef.current.value = ''
  }

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const raw = e.target.files?.[0]
    if (!raw) return
    await processFile(raw)
  }

  async function handleDrop(e: React.DragEvent<HTMLLabelElement>) {
    e.preventDefault()
    const file = e.dataTransfer.files?.[0]
    if (file) await processFile(file)
  }

  async function handleExtract() {
    if (!selectedFile) return
    setState('extracting')
    setError(null)

    try {
      const extraction = await getMandateDocument().extractMandateDocument({ file: selectedFile })
      setSelectedFile(null)
      setState('idle')
      onExtractionComplete?.(extraction)
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { messages?: { file?: string[] } } } })
          ?.response?.data?.messages?.file?.[0] ?? t('mandateDocument.extractError')
      setError(msg)
      setState('selected')
    }
  }

  function handleCancel() {
    setSelectedFile(null)
    setError(null)
    setState('idle')
  }

  const extractLabel = state === 'extracting'
    ? t('mandateDocument.uploadingAndExtracting')
    : t('mandateDocument.extract')

  return (
    <div
      style={{
        borderTop: `1px solid ${theme.colors.border.light}`,
        paddingTop: theme.spacing.lg,
        marginTop: theme.spacing.sm,
      }}
      data-testid="mandate-document-section"
    >
      <div
        style={{
          fontSize: '11px',
          fontWeight: theme.typography.fontWeight.semibold,
          color: theme.colors.text.muted,
          letterSpacing: '0.05em',
          textTransform: 'uppercase',
          marginBottom: '10px',
        }}
      >
        {t('mandateDocument.title')}
      </div>

      {error && (
        <div
          style={{
            color: theme.colors.semantic.danger,
            fontSize: theme.typography.fontSize.xs,
            marginBottom: theme.spacing.sm,
            padding: '6px 10px',
            background: theme.badges.danger.bg,
            borderRadius: '4px',
          }}
          data-testid="mandate-document-error"
        >
          {error}
        </div>
      )}

      {/* ── Idle: file picker ── */}
      {state === 'idle' && (
        <label
          style={{
            display: 'block',
            border: `2px dashed ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.sm,
            padding: '20px',
            textAlign: 'center',
            cursor: 'pointer',
            color: theme.colors.text.secondary,
          }}
          data-testid="mandate-document-dropzone"
          onDragOver={(e) => e.preventDefault()}
          onDrop={handleDrop}
        >
          <input
            ref={inputRef}
            type="file"
            accept="image/*,.pdf"
            style={{ display: 'none' }}
            onChange={handleFileChange}
            data-testid="mandate-document-input"
          />
          <div style={{ fontSize: '24px', marginBottom: '6px' }}>📎</div>
          <div style={{ fontSize: theme.typography.fontSize.sm, fontWeight: theme.typography.fontWeight.medium, color: theme.colors.text.primary, marginBottom: '4px' }}>
            {t('mandateDocument.dropzone')}
          </div>
          <div style={{ fontSize: '11px' }}>JPEG · PNG · HEIC · PDF</div>
        </label>
      )}

      {/* ── Selected: preview before extracting ── */}
      {(state === 'selected' || state === 'extracting') && selectedFile && (
        <div
          style={{
            border: `2px solid ${theme.colors.semantic.primary}`,
            borderRadius: theme.borderRadius.sm,
            padding: theme.spacing.md,
            background: theme.badges.info.bg,
          }}
          data-testid="mandate-document-preview"
        >
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: '10px', marginBottom: '10px' }}>
            <div style={{ fontSize: '28px', flexShrink: 0 }}>
              {selectedFile.type === 'application/pdf' ? '📄' : '🖼️'}
            </div>
            <div>
              <div style={{ fontSize: theme.typography.fontSize.sm, fontWeight: theme.typography.fontWeight.semibold, color: theme.colors.text.primary }}>
                {selectedFile.name}
              </div>
              <div style={{ fontSize: '11px', color: theme.colors.text.muted }}>
                {formatBytes(originalSize)} → {formatBytes(selectedFile.size)}{' '}
                {selectedFile.type !== 'application/pdf' && `(${t('mandateDocument.compressed')})`}
              </div>
              {selectedFile.type !== 'application/pdf' && (
                <div style={{ fontSize: '11px', color: theme.colors.text.secondary }}>
                  {t('mandateDocument.willConvert')}
                </div>
              )}
            </div>
          </div>
          <div style={{ display: 'flex', gap: theme.spacing.sm }}>
            <button
              onClick={handleExtract}
              disabled={state === 'extracting'}
              style={{
                flex: 1,
                padding: theme.spacing.sm,
                background: state === 'extracting' ? theme.activeTint.primaryBorder : theme.colors.semantic.primary,
                color: 'white',
                border: 'none',
                borderRadius: '6px',
                cursor: state === 'extracting' ? 'wait' : 'pointer',
                fontSize: theme.typography.fontSize.sm,
                fontWeight: theme.typography.fontWeight.medium,
              }}
              data-testid="mandate-document-upload-btn"
            >
              {extractLabel}
            </button>
            {state !== 'extracting' && (
              <button
                onClick={handleCancel}
                style={{
                  padding: '8px 12px',
                  background: theme.colors.bg.secondary,
                  color: theme.colors.text.secondary,
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: '6px',
                  cursor: 'pointer',
                  fontSize: theme.typography.fontSize.sm,
                }}
                data-testid="mandate-document-cancel-btn"
              >
                ✕
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
