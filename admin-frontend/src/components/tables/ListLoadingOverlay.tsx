/**
 * ListLoadingOverlay
 *
 * Wraps the *results* region of a list page — the table, or the mobile card
 * list — and lays a spinner over it while a refresh is in flight, leaving the
 * region itself mounted underneath.
 *
 * This exists because the obvious alternative is wrong. Swapping the whole page
 * (or the whole results region) for a loading message unmounts everything above
 * it, and on a list page the thing above it is the toolbar holding the focused
 * search box: the next debounced request tore the input out from under the
 * admin mid-word, and the rest of the keystrokes went nowhere (#137).
 *
 * The first load is a different case and is not this component's job — a page
 * with nothing to show yet may render whatever it likes; see `hasLoaded` on
 * `useListQuery`.
 *
 * Usage:
 *   <ListLoadingOverlay loading={loading} label={t('common.loading')} testId="products-list-loading">
 *     <table>…</table>
 *   </ListLoadingOverlay>
 */

import type { ReactNode } from 'react'

interface ListLoadingOverlayProps {
  loading: boolean
  /** Shown next to the spinner and read out by screen readers. */
  label: string
  children: ReactNode
  testId?: string
}

export function ListLoadingOverlay({ loading, label, children, testId }: ListLoadingOverlayProps) {
  return (
    <div
      style={{
        position: 'relative',
        // A refresh that follows an empty result has nothing underneath it, so
        // without a floor the overlay would collapse to nothing.
        minHeight: loading ? '120px' : undefined,
      }}
    >
      {children}
      {loading && (
        <div
          data-testid={testId}
          role="status"
          aria-live="polite"
          style={{
            position: 'absolute',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            borderRadius: '8px',
            backgroundColor: 'rgba(10, 18, 32, 0.72)',
            zIndex: 1,
          }}
        >
          {/* Sticky, not centred: a long table is taller than the viewport, and
              a spinner centred over the whole of it lands below the fold. The
              chip keeps the label legible whatever row it happens to cover. */}
          <div
            style={{
              position: 'sticky',
              top: '16px',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              padding: '16px',
            }}
          >
            <span
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '10px',
                padding: '8px 16px',
                borderRadius: '999px',
                backgroundColor: '#0d1829',
                border: '1px solid #2d3748',
                boxShadow: '0 8px 20px rgba(0, 0, 0, 0.45)',
                color: '#cbd5e1',
                fontSize: '14px',
              }}
            >
              <span
                style={{
                  width: '16px',
                  height: '16px',
                  borderRadius: '50%',
                  border: '2px solid rgba(59, 130, 246, 0.25)',
                  borderTopColor: '#3b82f6',
                  animation: 'listLoadingSpin 0.7s linear infinite',
                }}
              />
              {label}
            </span>
          </div>
          <style>{`
            @keyframes listLoadingSpin {
              to { transform: rotate(360deg); }
            }
          `}</style>
        </div>
      )}
    </div>
  )
}
