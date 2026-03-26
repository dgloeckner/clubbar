import { adminAxios } from './client'

export interface MandateDocumentInfo {
  uploaded_at: string
  file_size_bytes: number
  original_filename: string
  extraction_status: string | null
}

/**
 * Upload or replace a mandate document for a member.
 * Accepts JPEG, PNG, or PDF (HEIC must be converted to JPEG client-side first).
 */
export async function uploadMandateDocument(
  memberId: string,
  file: File
): Promise<MandateDocumentInfo> {
  const formData = new FormData()
  formData.append('file', file)

  const response = await adminAxios.post<MandateDocumentInfo>(
    `/admin/members/${memberId}/mandate-document`,
    formData
  )
  return response.data
}

/**
 * Open the stored PDF inline in a new browser tab.
 */
export function openMandateDocument(memberId: string): void {
  window.open(`/api/admin/members/${memberId}/mandate-document`, '_blank')
}

/**
 * Delete the stored mandate document.
 */
export async function deleteMandateDocument(memberId: string): Promise<void> {
  await adminAxios.delete(`/admin/members/${memberId}/mandate-document`)
}
