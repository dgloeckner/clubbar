/**
 * Transaction display utilities.
 * Extracted from services/transactions.ts.
 */

export function formatTransactionType(type: string): string {
  const labels: Record<string, string> = {
    purchase: 'Purchase',
    correction: 'Correction',
  }
  return labels[type] ?? type
}

export function getTransactionTypeColor(
  type: string
): { bg: string; text: string } {
  const colors: Record<string, { bg: string; text: string }> = {
    purchase: { bg: 'rgba(59, 130, 246, 0.1)', text: '#3b82f6' },
    correction: { bg: 'rgba(251, 146, 60, 0.1)', text: '#f97316' },
  }
  return colors[type] ?? { bg: 'rgba(107, 114, 128, 0.1)', text: '#64748b' }
}

export function getAmountColor(amountCents: number): string {
  if (amountCents > 0) return 'text-red-600'
  if (amountCents < 0) return 'text-green-600'
  return 'text-gray-600'
}
