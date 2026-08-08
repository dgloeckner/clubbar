import { useCallback, useEffect, useMemo, useRef } from 'react'

export interface LatestRequest {
  /**
   * Abort whatever is still in flight and return the signal for the new
   * request. Pass it to the generated client as `call(params, { signal })`.
   */
  next: () => AbortSignal
  /** Abort the in-flight request without starting a new one. */
  abort: () => void
}

/**
 * Keeps a page from rendering the answer to a question it no longer asks.
 *
 * A list page fires a fresh request on every keystroke, filter click and page
 * change, and the responses are free to come back in any order — a slow "an"
 * search landing after the cleared one leaves filtered rows under an empty
 * search box. `next()` makes the newest request the only one that may write to
 * state: it aborts the previous one and hands back a signal the caller re-checks
 * before calling any setter.
 *
 * Checking `signal.aborted` rather than catching the abort error is deliberate.
 * When the response has already arrived by the time the abort lands, axios
 * resolves it normally and no error is ever thrown — only the flag still says
 * this caller is no longer the current request. The same check belongs in
 * `finally` blocks, so a superseded request cannot clear the spinner the newer
 * one just raised.
 *
 * The in-flight request is aborted on unmount.
 */
export function useLatestRequest(): LatestRequest {
  const controllerRef = useRef<AbortController | null>(null)

  const abort = useCallback(() => {
    controllerRef.current?.abort()
    controllerRef.current = null
  }, [])

  const next = useCallback(() => {
    controllerRef.current?.abort()
    const controller = new AbortController()
    controllerRef.current = controller
    return controller.signal
  }, [])

  useEffect(
    () => () => {
      controllerRef.current?.abort()
      controllerRef.current = null
    },
    []
  )

  // Stable across renders: callers list this object in effect and `useCallback`
  // dependency arrays, and a fresh identity per render would re-fire the very
  // requests the hook exists to keep under control.
  return useMemo(() => ({ next, abort }), [next, abort])
}
