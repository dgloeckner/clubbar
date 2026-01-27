/**
 * Icon Component Types
 * Implements E2E Testing Pattern 005: Using Test IDs
 */

import React from 'react'

/**
 * IconProps - Standard props for all icon components
 * Extends SVG element props for flexibility
 */
export interface IconProps extends React.SVGProps<SVGSVGElement> {
  size?: number
}
