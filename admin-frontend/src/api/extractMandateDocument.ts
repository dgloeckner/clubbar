import { adminAxios } from './client'
import type { ExtractionResult } from './generated/extractionResult'

export type { ExtractionField } from './generated/extractionField'
export type { ExtractionResult } from './generated/extractionResult'

/**
 * Send a mandate scan to the backend for LLM field extraction.
 * No file is stored — this is purely for the create-from-scan flow.
 *
 * Throws on 409 (LLM not configured), 422 (invalid file), or 500 (extraction failed).
 */
export async function extractMandateDocument(file: File): Promise<ExtractionResult> {
  const formData = new FormData()
  formData.append('file', file)

  const response = await adminAxios.post<ExtractionResult>(
    '/admin/mandate-document/extract',
    formData,
    { headers: { 'Content-Type': undefined } }
  )
  return response.data
}
