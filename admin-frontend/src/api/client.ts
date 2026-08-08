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
import { getApiErrorMessage } from '../utils/apiErrors'

// ─── CSRF ────────────────────────────────────────────────────────────────────

let csrfToken: string | null = localStorage.getItem('csrf_token')

export function setCsrfToken(token: string | null) {
  csrfToken = token
  if (token) {
    localStorage.setItem('csrf_token', token)
  } else {
    localStorage.removeItem('csrf_token')
  }
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

axiosInstance.interceptors.response.use(
  (response) => {
    decrementPending()
    return response
  },
  (error) => {
    decrementPending()
    if (error.response?.status === 401) {
      localStorage.removeItem('admin_id')
      localStorage.removeItem('email')
      localStorage.removeItem('display_name')
      localStorage.removeItem('locale')
      localStorage.removeItem('csrf_token')
      csrfToken = null
      if (window.location.pathname !== '/login') {
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
  // multipart endpoints (uploadMandateDocument, extractMandateDocument, ...) pass a
  // FormData body without overriding that header — axios then sends the JSON
  // Content-Type with a multipart body, and the backend never sees any uploaded
  // files. Clearing it here lets the browser set the correct
  // "multipart/form-data; boundary=..." header itself.
  const headers =
    config.data instanceof FormData ? { ...config.headers, 'Content-Type': undefined } : config.headers

  return axiosInstance({ ...config, headers, signal: options?.signal }).then(({ data }) => data)
}

// ─── File download ────────────────────────────────────────────────────────────

/**
 * A failed blob request carries its error body as a Blob, so the caller would
 * otherwise only ever see "Request failed with status code 400". Read the body
 * back and use the API's own message when there is one.
 */
async function readDownloadError(error: unknown): Promise<Error> {
  if (!axios.isAxiosError(error) || !(error.response?.data instanceof Blob)) {
    return error instanceof Error ? error : new Error(String(error))
  }
  try {
    // Swap the Blob for the parsed body so the shared extractor can read it —
    // it knows all three shapes the API raises, including the `messages` map
    // that carries no top-level `message` at all.
    error.response.data = JSON.parse(await error.response.data.text())
  } catch {
    // Not a JSON error body — the transport message is all there is.
    return error
  }
  return new Error(getApiErrorMessage(error, error.message))
}

export async function downloadFile(url: string, fallbackFilename: string): Promise<void> {
  let response
  try {
    response = await axiosInstance.get(url, { responseType: 'blob' })
  } catch (error) {
    throw await readDownloadError(error)
  }
  const contentDisposition = response.headers['content-disposition']
  let filename = fallbackFilename
  if (contentDisposition) {
    const match = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
    if (match?.[1]) filename = match[1].replace(/['"]/g, '')
  }
  const objectUrl = URL.createObjectURL(response.data)
  const a = document.createElement('a')
  a.href = objectUrl
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(objectUrl)
}

export default axiosInstance

// Named export for file upload and streaming API calls
export { axiosInstance as adminAxios }
