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
export { AuditLogIcon } from './AuditLogIcon'
export { UsersIcon } from './UsersIcon'
export { PackageIcon } from './PackageIcon'
export { BookIcon } from './BookIcon'
export { ReceiptIcon } from './ReceiptIcon'
export { ChartIcon } from './ChartIcon'
export { SettingsIcon } from './SettingsIcon'

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

// Admin user icons (for future use in members, admin management, etc.)
export { AdminUserIcon } from './admin-user-icons'
export { AdminGearIcon } from './admin-user-icons'
export { AdminCrownIcon } from './admin-user-icons'
export { AdminStarIcon } from './admin-user-icons'
export { AdminKeyIcon } from './admin-user-icons'
