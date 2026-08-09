import { downloadFile } from './client'
import type { MandateDocument } from './generated/mandateDocument'

// Re-export generated type under the name used by components and tests.
export type MandateDocumentInfo = MandateDocument

/**
 * Save the stored PDF to disk.
 *
 * It used to open in a tab, which meant the backend had to serve an
 * admin-uploaded file inline in the admin panel's own origin; it now sends
 * `Content-Disposition: attachment` (#107), so navigating to the URL would just
 * leave a blank tab behind. Going through `downloadFile` also puts the request
 * on the API client, so it carries auth and surfaces the API's error message
 * instead of rendering a JSON 404 as a page.
 */
export function downloadMandateDocument(memberId: string): Promise<void> {
  return downloadFile(`/admin/members/${memberId}/mandate-document`, 'mandate.pdf')
}
