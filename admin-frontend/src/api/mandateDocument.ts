import type { MandateDocument } from './generated/mandateDocument'

// Re-export generated type under the name used by components and tests.
export type MandateDocumentInfo = MandateDocument

/**
 * Open the stored PDF inline in a new browser tab.
 * No generated equivalent: the generated getMandateDocument() returns a Blob via
 * customInstance rather than a directly navigable URL.
 */
export function openMandateDocument(memberId: string): void {
  window.open(`/api/admin/members/${memberId}/mandate-document`, '_blank')
}
