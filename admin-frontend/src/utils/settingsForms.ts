/**
 * Settings form validation
 *
 * The create modals on the Settings page send what was typed straight to the
 * API. An empty required field comes back as a 422 the modal cannot attribute
 * to an input, so the obvious cases are caught here first and reported per
 * field. The backend stays the authority — this only spares the admin a round
 * trip for a field they can see is empty.
 */

/** i18n keys; the component that renders the message resolves them. */
export const VALIDATION_REQUIRED = 'validation.required'
export const VALIDATION_INVALID_EMAIL = 'validation.invalidEmail'

// Deliberately permissive: enough to catch a display name typed into the email
// field, not an attempt to restate RFC 5322.
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export interface CreateAdminFormValues {
  email: string
  display_name: string
  /** Never pre-selected (CONTEXT.md's Role entry) — an explicit choice. */
  roles: string[]
}

export interface CreateTerminalFormValues {
  name: string
  device_id: string
}

/** Field name → i18n key of the reason it was rejected. Empty when valid. */
export function validateCreateAdminForm(values: CreateAdminFormValues): Record<string, string> {
  const errors: Record<string, string> = {}

  const email = values.email.trim()
  if (!email) {
    errors.email = VALIDATION_REQUIRED
  } else if (!EMAIL_PATTERN.test(email)) {
    errors.email = VALIDATION_INVALID_EMAIL
  }

  if (!values.display_name.trim()) {
    errors.display_name = VALIDATION_REQUIRED
  }

  if (values.roles.length === 0) {
    errors.roles = VALIDATION_REQUIRED
  }

  return errors
}

/** Field name → i18n key of the reason it was rejected. Empty when valid. */
export function validateCreateTerminalForm(values: CreateTerminalFormValues): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!values.name.trim()) {
    errors.name = VALIDATION_REQUIRED
  }

  if (!values.device_id.trim()) {
    errors.device_id = VALIDATION_REQUIRED
  }

  return errors
}
