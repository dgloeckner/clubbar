/**
 * API Client — Axios instance + custom orval mutator
 *
 * Cross-cutting concerns:
 * - CSRF token management (persisted to localStorage)
 * - Global loading state pub/sub (drives LoadingIndicator)
 * - 401 → redirect to /login
 * - File download helper
 *
 * NOTE: Bearer token auth is NOT used — the API uses cookie-based session auth.
 */

import axios from 'axios'
import type { AxiosRequestConfig } from 'axios'

// ─── CSRF ────────────────────────────────────────────────────────────────────

// Kept in memory only (#109) — it belongs to the PHP session, not to storage
// that outlives it and stays readable by any script that achieves XSS. The
// backend hands out a fresh token on every /auth/profile response, so nothing
// here needs to survive a reload.
let csrfToken: string | null = null

export function setCsrfToken(token: string | null) {
  csrfToken = token
  // Migration: earlier versions persisted this token in localStorage. Clear
  // any leftover value so it doesn't outlive the session it belonged to.
  localStorage.removeItem('csrf_token')
}

// ─── Loading state ───────────────────────────────────────────────────────────

let pendingRequests = 0
const loadingStateCallbacks: Array<(isLoading: boolean) => void> = []

export function onLoadingStateChange(callback: (isLoading: boolean) => void): () => void {
  loadingStateCallbacks.push(callback)
  return () => {
    const index = loadingStateCallbacks.indexOf(callback)
    if (index > -1) loadingStateCallbacks.splice(index, 1)
  }
}

function notifyLoadingState() {
  const isLoading = pendingRequests > 0
  loadingStateCallbacks.forEach(cb => cb(isLoading))
}

function incrementPending() {
  const wasLoading = pendingRequests > 0
  pendingRequests++
  if (!wasLoading) notifyLoadingState()
}

function decrementPending() {
  const wasLoading = pendingRequests > 0
  pendingRequests = Math.max(0, pendingRequests - 1)
  const isNowLoading = pendingRequests > 0
  if (wasLoading !== isNowLoading) notifyLoadingState()
}

// ─── Axios instance ───────────────────────────────────────────────────────────

const axiosInstance = axios.create({
  baseURL: '/api',
  headers: { 'Content-Type': 'application/json' },
  withCredentials: true,
})

axiosInstance.interceptors.request.use(
  (config) => {
    if (csrfToken && config.method && !['get', 'head', 'options'].includes(config.method)) {
      config.headers['X-CSRF-Token'] = csrfToken
    }
    incrementPending()
    return config
  },
  (error) => {
    decrementPending()
    return Promise.reject(error)
  }
)

/**
 * A 401 means one of two unrelated things, and only one of them is about the
 * session.
 *
 * `invalid_credentials` is a *rejected credential* on a perfectly live session:
 * a wrong password or TOTP code in a step-up re-authentication (#337) — the
 * email change, the password change, the cross-account resets, the encryption
 * key mutations. Signing the admin out on those is wrong twice over: it
 * discards a valid session because someone mistyped, and it destroys the very
 * dialog `StepUpConfirmDialog` keeps open so the credential can be retried.
 *
 * Every other 401 (`admin_not_authenticated`, `session_expired`,
 * `mfa_session_expired`) means the session really is gone. This is an
 * exclusion rather than an allowlist so an unrecognised code still ends the
 * session — the safe direction to fail in.
 */
function isRejectedCredential(error: unknown): boolean {
  const response = (error as { response?: { data?: { error?: string } } })?.response
  return response?.data?.error === 'invalid_credentials'
}

/**
 * A 403 `insufficient_role` (ADR-0044) is not a broken request: it is the
 * server disagreeing with what the panel believes the caller may do, which
 * happens whenever a role is revoked while a tab stays open. The panel cannot
 * fix that by retrying — it has to go and re-read the roles it holds, so the
 * navigation and the route guard tell the truth again.
 *
 * Registered by `AuthProvider`, which owns the roles; this module only knows
 * that somebody wants to hear about it.
 */
let insufficientRoleHandler: (() => void) | null = null

export function setInsufficientRoleHandler(handler: (() => void) | null): void {
  insufficientRoleHandler = handler
}

function isInsufficientRole(error: unknown): boolean {
  const response = (error as { response?: { status?: number; data?: { error?: string } } })?.response
  return response?.status === 403 && response?.data?.error === 'insufficient_role'
}

/**
 * The screens that are *supposed* to work with no session at all.
 *
 * The 401 handler below signs the admin out and sends them to `/login`, which
 * is right almost everywhere and wrong on these: the panel's boot call to
 * `GET /auth/profile` answers 401 for a visitor who has no account yet, and
 * bouncing them would make the invitation link (migration 058) look dead to
 * the one person who cannot ask anybody why. `/login` was already exempt for
 * the same reason — this makes the exemption a list rather than a special case,
 * so the next public screen is one entry rather than one more `!==`.
 */
const SESSION_LESS_PATHS = [/^\/login\/?$/, /^\/invite\//]

function isSessionLessPath(pathname: string): boolean {
  return SESSION_LESS_PATHS.some((pattern) => pattern.test(pathname))
}

axiosInstance.interceptors.response.use(
  (response) => {
    decrementPending()
    return response
  },
  (error) => {
    decrementPending()
    if (isInsufficientRole(error)) {
      insufficientRoleHandler?.()
    }
    if (error.response?.status === 401 && !isRejectedCredential(error)) {
      localStorage.removeItem('admin_id')
      localStorage.removeItem('email')
      localStorage.removeItem('display_name')
      localStorage.removeItem('locale')
      setCsrfToken(null)
      if (!isSessionLessPath(window.location.pathname)) {
        window.location.href = '/login'
      }
    }
    return Promise.reject(error)
  }
)

// ─── orval mutator ────────────────────────────────────────────────────────────

export const customInstance = <T>(
  config: AxiosRequestConfig,
  options?: { signal?: AbortSignal }
): Promise<T> => {
  // The instance defaults to Content-Type: application/json (set above). Generated
  // multipart endpoints (importMembersPreview, ...) pass a FormData body without
  // overriding that header — axios then sends the JSON Content-Type with a
  // multipart body, and the backend never sees any uploaded files. Clearing it
  // here lets the browser set the correct "multipart/form-data; boundary=..."
  // header itself.
  const headers =
    config.data instanceof FormData ? { ...config.headers, 'Content-Type': undefined } : config.headers

  return axiosInstance({ ...config, headers, signal: options?.signal }).then(({ data }) => data)
}

// ─── File download ────────────────────────────────────────────────────────────

/**
 * Save an already-fetched blob to disk.
 *
 * The anchor dance below was copy-pasted into five call sites, two of which
 * forgot to revoke the object URL and one of which never attached the anchor to
 * the document (Firefox ignores a click on a detached anchor) — #121. Pages that
 * obtain a blob from the generated client should route it through here; pages
 * that only have a URL should use `downloadFile`, which respects the filename
 * the backend sends and reports the API's own error message.
 */
export function downloadBlob(blob: Blob, filename: string): void {
  const objectUrl = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = objectUrl
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(objectUrl)
}

/**
 * A failed blob request carries its error body as a Blob, so the caller would
 * otherwise only ever see "Request failed with status code 400". Read the body
 * back and hand the caller the original error with the parsed body in place.
 *
 * The *error itself* is returned, not a new `Error` carrying its message: a
 * SEPA export refuses with a business rule like any other endpoint, and the
 * `reason` code is on the response. Flattening it to a message string here
 * would leave the page with nothing to translate but the backend's English
 * sentence (#757). Pages read this with `useApiError`, which still falls back
 * to that message when no reason came with it.
 */
async function readDownloadError(error: unknown): Promise<Error> {
  if (!axios.isAxiosError(error) || !(error.response?.data instanceof Blob)) {
    return error instanceof Error ? error : new Error(String(error))
  }
  try {
    // Swap the Blob for the parsed body so the shared extractors can read it —
    // they know all three shapes the API raises, including the `messages` map
    // that carries no top-level `message` at all.
    error.response.data = JSON.parse(await error.response.data.text())
  } catch {
    // Not a JSON error body — the transport message is all there is.
    return error
  }
  return error
}

/**
 * Fetch a file and save it, honouring the filename the backend sends.
 *
 * Returns the response headers, lower-cased by axios. Some downloads say
 * something the file itself cannot: the SEPA export reports the members it
 * left out of the bank file on `X-Uncollectable-Members` and friends, because
 * the body is the pain.008 document and has nowhere to put a warning (#114).
 * Callers with nothing to read may ignore the return value.
 *
 * `body` switches the request to POST. The SEPA export needs it: the club's
 * private key travels in the request so the sealed IBANs can be opened for
 * that one file (ADR-0036), which a GET has nowhere to carry.
 */
export async function downloadFile(
  url: string,
  fallbackFilename: string,
  body?: unknown
): Promise<Record<string, string>> {
  let response
  try {
    response =
      body === undefined
        ? await axiosInstance.get(url, { responseType: 'blob' })
        : await axiosInstance.post(url, body, { responseType: 'blob' })
  } catch (error) {
    throw await readDownloadError(error)
  }
  const contentDisposition = response.headers['content-disposition']
  let filename = fallbackFilename
  if (contentDisposition) {
    const match = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
    if (match?.[1]) filename = match[1].replace(/['"]/g, '')
  }
  downloadBlob(response.data, filename)

  return response.headers as Record<string, string>
}

export default axiosInstance

// Named export for file upload and streaming API calls
export { axiosInstance as adminAxios }
