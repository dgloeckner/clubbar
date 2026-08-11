/**
 * Shared modal lifecycle: closes an open dialog on Escape and focuses its
 * content when it opens.
 *
 * Escape is bound on `window` rather than the dialog's own `onKeyDown`
 * (#136) — an `onKeyDown` handler on the backdrop only fires while that
 * element itself has focus, so it silently stops working the moment focus
 * moves into an input inside the dialog. `LoginPage.tsx`'s `TotpInfoModal`
 * established this window-level pattern first; this hook is the shared
 * version other modals were missing.
 */

import { useEffect, useRef } from 'react'

/** Calls `onClose` on Escape while `isOpen`, via a window listener. */
export function useModalEscape(isOpen: boolean, onClose: () => void): void {
  useEffect(() => {
    if (!isOpen) return

    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [isOpen, onClose])
}

/**
 * `useModalEscape` plus focus-on-open. Returns a ref for the dialog's
 * content element (`tabIndex={-1}` so it can receive focus programmatically).
 */
export function useModalDialog(isOpen: boolean, onClose: () => void) {
  const contentRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (isOpen) {
      contentRef.current?.focus()
    }
  }, [isOpen])

  useModalEscape(isOpen, onClose)

  return contentRef
}
