/**
 * SEPA configuration payload helpers
 *
 * The settings form edits one flat object, but the API has two shapes for it:
 * `POST /admin/sepa-config` carries the creditor_id for the initial setup, and
 * `PATCH /admin/sepa-config` never does, because the creditor ID is immutable
 * once set (ADR-0007). Every other field — including the payment reference
 * prefix that shows up on member bank statements — belongs in both payloads.
 */

import type { SepaConfig, SepaConfigRequest, SepaConfigUpdateRequest } from '../api/generated/model'

/**
 * The bounds on the mandate reference prefix (#936), mirroring
 * `MandateReferenceMinter` on the backend.
 *
 * The charset is SEPA's — `0-9 a-z A-Z + ? / - : ( ) . , '` — because it is what
 * a bank will carry in `<MndtId>`. The length is what keeps prefix + separator +
 * number inside SEPA's 35 characters for every number the counter can reach, so
 * a valid prefix can never produce an invalid reference.
 */
export const MANDATE_REFERENCE_PREFIX_MAX_LENGTH = 10
export const MANDATE_REFERENCE_PREFIX_PATTERN = /^[0-9A-Za-z+?/\-:().,']+$/

/** The editable shape of the SEPA settings form. */
export interface SepaConfigFormData {
  creditor_id?: string
  creditor_name?: string
  creditor_iban?: string
  creditor_address_street?: string
  creditor_address_city?: string
  creditor_address_country?: string
  payment_reference_prefix?: string
  mandate_template_url?: string
  mandate_reference_prefix?: string
}

/**
 * Whether the creditor ID has already been set.
 *
 * The backend keeps a singleton config row that exists (with empty columns)
 * before anything is configured, so the presence of a config object says
 * nothing — only a non-empty creditor_id does. It decides both whether the
 * field is still editable and whether saving creates or updates.
 */
export function isCreditorIdSet(config: SepaConfig | null | undefined): boolean {
  return !!config?.creditor_id?.trim()
}

/** Payload for the initial setup (POST) — creditor_id included and required. */
export function buildCreateSepaConfigRequest(form: SepaConfigFormData): SepaConfigRequest {
  return {
    creditor_id: form.creditor_id ?? '',
    creditor_name: form.creditor_name ?? '',
    creditor_iban: form.creditor_iban ?? '',
    creditor_address_street: form.creditor_address_street ?? '',
    creditor_address_city: form.creditor_address_city ?? '',
    creditor_address_country: form.creditor_address_country ?? '',
    payment_reference_prefix: form.payment_reference_prefix ?? '',
    mandate_template_url: form.mandate_template_url ?? '',
    mandate_reference_prefix: form.mandate_reference_prefix ?? '',
  }
}

/**
 * Payload for a later edit (PATCH) — every field except the immutable creditor_id.
 *
 * The creditor IBAN is overwrite-only (#392). The GET masks it, so the form has
 * nothing to prefill the field with and it is blank on every save that did not
 * deliberately retype it; sending that blank on would empty the account the
 * collection is paid into. Omitting the key is what the backend reads as "keep
 * the stored IBAN", so a blank field drops out of the payload entirely.
 */
export function buildUpdateSepaConfigRequest(form: SepaConfigFormData): SepaConfigUpdateRequest {
  const payload: SepaConfigUpdateRequest = {
    creditor_name: form.creditor_name,
    creditor_address_street: form.creditor_address_street,
    creditor_address_city: form.creditor_address_city,
    creditor_address_country: form.creditor_address_country,
    payment_reference_prefix: form.payment_reference_prefix ?? '',
    mandate_template_url: form.mandate_template_url ?? '',
    // Sent even when blank, unlike the IBAN: blank here means "go back to the
    // default prefix", which is a change the admin can make and the backend
    // stores as NULL (#936).
    mandate_reference_prefix: form.mandate_reference_prefix ?? '',
  }

  if (form.creditor_iban?.trim()) {
    payload.creditor_iban = form.creditor_iban
  }

  return payload
}
