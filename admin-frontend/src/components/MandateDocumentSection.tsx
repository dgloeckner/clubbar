import React, { useRef, useState } from 'react'
import imageCompression from 'browser-image-compression'
import heic2any from 'heic2any'
import {
  MandateDocumentInfo,
  openMandateDocument,
  uploadMandateDocument,
} from '../api/mandateDocument'
import { useTranslation } from 'react-i18next'

interface Props {
  memberId: string
  initialDocument: MandateDocumentInfo | null
}

type ComponentState = 'idle' | 'selected' | 'uploading' | 'stored'

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

export function MandateDocumentSection({ memberId, initialDocument }: Props) {
  const { t } = useTranslation()
  const inputRef = useRef<HTMLInputElement>(null)

  const [state, setState] = useState<ComponentState>(
    initialDocument ? 'stored' : 'idle'
  )
  const [mandateDoc, setMandateDoc] = useState<MandateDocumentInfo | null>(initialDocument)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [originalSize, setOriginalSize] = useState<number>(0)
  const [error, setError] = useState<string | null>(null)

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const raw = e.target.files?.[0]
    if (!raw) return
    setError(null)
    setOriginalSize(raw.size)

    try {
      let processedFile: File = raw

      // Convert HEIC to JPEG first.
      // heic2any returns Blob | Blob[] — take first item when it returns an array
      // (burst/multi-image HEIC files).
      if (raw.type === 'image/heic' || raw.name.toLowerCase().endsWith('.heic')) {
        const result = await heic2any({ blob: raw, toType: 'image/jpeg', quality: 0.85 })
        const blob   = Array.isArray(result) ? result[0] : result
        processedFile = new File(
          [blob],
          raw.name.replace(/\.heic$/i, '.jpg'),
          { type: 'image/jpeg' }
        )
      }

      // Compress images (not PDFs)
      if (processedFile.type !== 'application/pdf') {
        processedFile = await imageCompression(processedFile, {
          maxSizeMB: 2,
          maxWidthOrHeight: 2000,
          useWebWorker: true,
        })
      }

      setSelectedFile(processedFile)
      setState('selected')
    } catch (err) {
      setError(t('mandateDocument.processingError'))
    }

    // Reset input so the same file can be re-selected if cancelled
    if (inputRef.current) inputRef.current.value = ''
  }

  async function handleUpload() {
    if (!selectedFile) return
    setState('uploading')
    setError(null)

    try {
      const doc = await uploadMandateDocument(memberId, selectedFile)
      setMandateDoc(doc)
      setSelectedFile(null)
      setState('stored')
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { messages?: { file?: string[] } } } })
          ?.response?.data?.messages?.file?.[0] ?? t('mandateDocument.uploadError')
      setError(msg)
      setState('selected')
    }
  }

  function handleCancel() {
    setSelectedFile(null)
    setError(null)
    setState(mandateDoc ? 'stored' : 'idle')
  }

  function handleReplace() {
    setState('idle')
    setSelectedFile(null)
    setError(null)
  }

  return (
    <div
      style={{
        borderTop: '1px solid #e2e8f0',
        paddingTop: '16px',
        marginTop: '8px',
      }}
      data-testid="mandate-document-section"
    >
      <div
        style={{
          fontSize: '11px',
          fontWeight: 600,
          color: '#64748b',
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
            color: '#dc2626',
            fontSize: '12px',
            marginBottom: '8px',
            padding: '6px 10px',
            background: '#fef2f2',
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
            border: '2px dashed #cbd5e1',
            borderRadius: '8px',
            padding: '20px',
            textAlign: 'center',
            cursor: 'pointer',
            color: '#94a3b8',
          }}
          data-testid="mandate-document-dropzone"
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
          <div style={{ fontSize: '13px', fontWeight: 500, color: '#475569', marginBottom: '4px' }}>
            {t('mandateDocument.dropzone')}
          </div>
          <div style={{ fontSize: '11px' }}>JPEG · PNG · HEIC · PDF</div>
        </label>
      )}

      {/* ── Selected: preview before upload ── */}
      {(state === 'selected' || state === 'uploading') && selectedFile && (
        <div
          style={{
            border: '2px solid #3b82f6',
            borderRadius: '8px',
            padding: '12px',
            background: '#eff6ff',
          }}
          data-testid="mandate-document-preview"
        >
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: '10px', marginBottom: '10px' }}>
            <div style={{ fontSize: '28px', flexShrink: 0 }}>
              {selectedFile.type === 'application/pdf' ? '📄' : '🖼️'}
            </div>
            <div>
              <div style={{ fontSize: '13px', fontWeight: 600, color: '#1e40af' }}>
                {selectedFile.name}
              </div>
              <div style={{ fontSize: '11px', color: '#64748b' }}>
                {formatBytes(originalSize)} → {formatBytes(selectedFile.size)}{' '}
                {selectedFile.type !== 'application/pdf' && `(${t('mandateDocument.compressed')})`}
              </div>
              {selectedFile.type !== 'application/pdf' && (
                <div style={{ fontSize: '11px', color: '#94a3b8' }}>
                  {t('mandateDocument.willConvert')}
                </div>
              )}
            </div>
          </div>
          <div style={{ display: 'flex', gap: '8px' }}>
            <button
              onClick={handleUpload}
              disabled={state === 'uploading'}
              style={{
                flex: 1,
                padding: '8px',
                background: state === 'uploading' ? '#93c5fd' : '#3b82f6',
                color: 'white',
                border: 'none',
                borderRadius: '6px',
                cursor: state === 'uploading' ? 'wait' : 'pointer',
                fontSize: '13px',
                fontWeight: 500,
              }}
              data-testid="mandate-document-upload-btn"
            >
              {state === 'uploading' ? t('mandateDocument.uploading') : t('mandateDocument.upload')}
            </button>
            {state !== 'uploading' && (
              <button
                onClick={handleCancel}
                style={{
                  padding: '8px 12px',
                  background: '#f1f5f9',
                  color: '#64748b',
                  border: 'none',
                  borderRadius: '6px',
                  cursor: 'pointer',
                  fontSize: '13px',
                }}
                data-testid="mandate-document-cancel-btn"
              >
                ✕
              </button>
            )}
          </div>
        </div>
      )}

      {/* ── Stored: document info ── */}
      {state === 'stored' && mandateDoc && (
        <div
          style={{
            border: '1px solid #bbf7d0',
            borderRadius: '8px',
            padding: '12px',
            background: '#f0fdf4',
          }}
          data-testid="mandate-document-stored"
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '10px' }}>
            <div style={{ fontSize: '28px' }}>📄</div>
            <div>
              <div
                style={{ fontSize: '13px', fontWeight: 600, color: '#166534' }}
                data-testid="mandate-document-filename"
              >
                {mandateDoc.original_filename}
              </div>
              <div style={{ fontSize: '11px', color: '#64748b' }}>
                {formatBytes(mandateDoc.file_size_bytes)} · {t('mandateDocument.uploaded')}{' '}
                {formatDate(mandateDoc.uploaded_at)}
              </div>
            </div>
          </div>
          <div style={{ display: 'flex', gap: '8px' }}>
            <button
              onClick={() => openMandateDocument(memberId)}
              style={{
                flex: 1,
                padding: '8px',
                background: '#f8fafc',
                border: '1px solid #e2e8f0',
                borderRadius: '6px',
                cursor: 'pointer',
                fontSize: '13px',
              }}
              data-testid="mandate-document-view-btn"
            >
              👁 {t('mandateDocument.view')}
            </button>
            <button
              onClick={handleReplace}
              style={{
                padding: '8px 12px',
                background: '#fef2f2',
                color: '#dc2626',
                border: '1px solid #fecaca',
                borderRadius: '6px',
                cursor: 'pointer',
                fontSize: '13px',
              }}
              data-testid="mandate-document-replace-btn"
            >
              {t('mandateDocument.replace')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
