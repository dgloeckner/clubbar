/**
 * useClipboardCopy — write a value to the clipboard and report what happened.
 *
 * The write is awaited, so a rejection (non-secure origin, unfocused document,
 * denied permission) is observable instead of silent. Callers showing a
 * one-time secret depend on that: the value may only be discarded once the
 * copy has actually succeeded, or the user has been told to copy it manually.
 */

import { useCallback, useState } from 'react'

export type CopyStatus = 'idle' | 'copied' | 'failed'

export interface ClipboardCopy {
  status: CopyStatus
  copy: (text: string) => Promise<boolean>
  reset: () => void
}

export function useClipboardCopy(): ClipboardCopy {
  const [status, setStatus] = useState<CopyStatus>('idle')

  const copy = useCallback(async (text: string): Promise<boolean> => {
    // navigator.clipboard is undefined on non-secure origins — treat the
    // missing API exactly like a rejected write.
    const clipboard = typeof navigator === 'undefined' ? undefined : navigator.clipboard

    if (!clipboard || typeof clipboard.writeText !== 'function') {
      setStatus('failed')
      return false
    }

    try {
      await clipboard.writeText(text)
      setStatus('copied')
      return true
    } catch {
      setStatus('failed')
      return false
    }
  }, [])

  const reset = useCallback(() => setStatus('idle'), [])

  return { status, copy, reset }
}
