/**
 * ActionButtons Component
 *
 * Renders a pair of action buttons (Edit/Delete) in a table cell:
 * - Consistent styling from design tokens
 * - Hover effects with color transitions
 * - Optional icons
 *
 * Usage:
 * <ActionButtons
 *   onEdit={() => openEditModal(item)}
 *   onDelete={() => handleDelete(item)}
 *   editTestId="products-edit-button-123"
 *   deleteTestId="products-delete-button-123"
 * />
 */

import { useState } from 'react'
import { EditIcon, TrashIcon } from '../icons'
import { tableSpacing, getButtonStyle } from '../../styles/tableTokens'

interface ActionButtonsProps {
  onEdit?: () => void
  onDelete?: () => void
  editTestId?: string
  deleteTestId?: string
  size?: 'small' | 'medium'
  cellTestId?: string
}

export function ActionButtons({
  onEdit,
  onDelete,
  editTestId,
  deleteTestId,
  size = 'small',
  cellTestId,
}: ActionButtonsProps) {
  const [editHovered, setEditHovered] = useState(false)
  const [deleteHovered, setDeleteHovered] = useState(false)

  const iconSize = size === 'small' ? 18 : 20

  return (
    <td
      data-testid={cellTestId}
      style={{
        padding: tableSpacing.cellPadding,
        display: 'flex',
        gap: tableSpacing.actionButtonGap,
        justifyContent: 'center',
      }}
    >
      {onEdit && (
        <button
          data-testid={editTestId}
          onClick={onEdit}
          style={getButtonStyle('primary', editHovered)}
          onMouseEnter={() => setEditHovered(true)}
          onMouseLeave={() => setEditHovered(false)}
        >
          <EditIcon size={iconSize} />
        </button>
      )}
      {onDelete && (
        <button
          data-testid={deleteTestId}
          onClick={onDelete}
          style={getButtonStyle('danger', deleteHovered)}
          onMouseEnter={() => setDeleteHovered(true)}
          onMouseLeave={() => setDeleteHovered(false)}
        >
          <TrashIcon size={iconSize} />
        </button>
      )}
    </td>
  )
}
