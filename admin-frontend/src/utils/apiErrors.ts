/**
 * API error extraction
 *
 * The backend reports a failure in one of three shapes, depending on where it
 * was raised (see `ErrorHandler` and the module controllers):
 *
 * | Shape                                    | Raised by                        |
 * |------------------------------------------|----------------------------------|
 * | `{ error, messages: { field: [...] } }`  | controller-level 422 validation  |
 * | `{ error, message, errors: { ... } }`    | `ValidationException`            |
 * | `{ error, message }`                     | every other `AppException`       |
 *
 * A caller that only reads `message` therefore shows nothing at all for the
 * most common failure an admin hits — a duplicate email or device ID, which
 * arrives as `messages` and has no `message` key.
 */

import axios from 'axios'

/** Per-field messages, keyed exactly as the API keys them (`email`, `name`, …). */
type FieldMessages = Record<string, string[] | string>

function responseData(err: unknown): Record<string, unknown> | null {
  if (!axios.isAxiosError(err)) {
    return null
  }
  const data = err.response?.data
  return data && typeof data === 'object' ? (data as Record<string, unknown>) : null
}

function fieldMessages(data: Record<string, unknown>): FieldMessages | null {
  for (const key of ['messages', 'errors'] as const) {
    const value = data[key]
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      return value as FieldMessages
    }
  }
  return null
}

/**
 * Field name → first message, for highlighting the input that was rejected.
 * Empty when the error carries no per-field detail.
 */
export function getApiFieldErrors(err: unknown): Record<string, string> {
  const data = responseData(err)
  const messages = data && fieldMessages(data)
  if (!messages) {
    return {}
  }

  const result: Record<string, string> = {}
  for (const [field, message] of Object.entries(messages)) {
    const first = Array.isArray(message) ? message[0] : message
    if (typeof first === 'string' && first.trim()) {
      result[field] = first
    }
  }
  return result
}

/**
 * The message to show the admin: what the API said, if it said anything
 * usable, otherwise the caller's fallback. Per-field messages are joined so
 * that a rejected field is named even when the banner is the only thing on
 * screen.
 */
export function getApiErrorMessage(err: unknown, fallback: string): string {
  const data = responseData(err)
  if (data) {
    const message = data.message
    if (typeof message === 'string' && message.trim()) {
      return message
    }

    const fieldErrors = Object.values(getApiFieldErrors(err))
    if (fieldErrors.length > 0) {
      return fieldErrors.join(' ')
    }
  }

  return fallback
}
