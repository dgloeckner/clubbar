/**
 * Icon Components - SVG Icons for UI
 * 
 * All icons use 20x20px size by default with strokeWidth: 2
 * Extends React SVG props for flexibility
 * 
 * Usage:
 *   import { UsersIcon, PlusIcon } from '@/components/icons'
 *   
 *   <UsersIcon size={24} className="custom-class" />
 *   <PlusIcon size={18} color="blue" />
 */

export { type IconProps } from './types'

// Core navigation icons
export { UsersIcon } from './UsersIcon'
export { PackageIcon } from './PackageIcon'
export { BookIcon } from './BookIcon'
export { ReceiptIcon } from './ReceiptIcon'
export { ChartIcon } from './ChartIcon'

// User and action icons
export { UserIcon } from './UserIcon'
export { LogoutIcon } from './LogoutIcon'
export { PlusIcon } from './PlusIcon'

// Table action icons
export { EditIcon } from './EditIcon'
export { TrashIcon } from './TrashIcon'

// Utility icons
export { CalendarIcon } from './CalendarIcon'
export { BankIcon } from './BankIcon'
