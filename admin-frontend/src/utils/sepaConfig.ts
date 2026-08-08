/**
 * SEPA configuration payload helpers
 *
 * The settings form edits one flat object, but the API has two shapes for it:
 * `POST /admin/sepa-config` carries the creditor_id for the initial setup, and
 * `PATCH /admin/sepa-config` never does, because the creditor ID is immutable
 * once set (ADR-0007). Every other field — including the payment reference
 * prefix that shows up on member bank statements — belongs in both payloads.
 */

import type { SepaConfig, SepaConfigRequest, SepaConfigUpdateRequest } from '../api/generated'

/** The editable shape of the SEPA settings form. */
export interface SepaConfigFormData {
  creditor_id?: string
  creditor_name?: string
  creditor_iban?: string
  creditor_address_street?: string
  creditor_address_city?: string
  creditor_address_country?: string
  payment_reference_prefix?: string
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
  }
}

/** Payload for a later edit (PATCH) — every field except the immutable creditor_id. */
export function buildUpdateSepaConfigRequest(form: SepaConfigFormData): SepaConfigUpdateRequest {
  return {
    creditor_name: form.creditor_name,
    creditor_iban: form.creditor_iban,
    creditor_address_street: form.creditor_address_street,
    creditor_address_city: form.creditor_address_city,
    creditor_address_country: form.creditor_address_country,
    payment_reference_prefix: form.payment_reference_prefix ?? '',
  }
}
