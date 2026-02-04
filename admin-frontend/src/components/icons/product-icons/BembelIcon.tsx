import { IconProps } from '../types'

export function BembelIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M8 5h6l1 1c1.5 2 2.5 4 2.5 7s-1 5-2 6c-.8.8-2 1.5-3.5 1.5s-2.7-.7-3.5-1.5c-1-1-2-3-2-6S6.5 8 8 6z" fill="#d1d5db" fillOpacity="0.5"/>
      <path d="M7.5 5.5h7" stroke="#3b82f6" strokeWidth="1.2"/>
      <path d="M7 4h8" stroke="#3b82f6" strokeWidth="1.5"/>
      <path d="M8.5 19c1 .5 2 .8 3.5.8s2.5-.3 3.5-.8" stroke="#3b82f6" strokeWidth="1"/>
      <path d="M16 8c2.5.5 3.5 2 3.5 4s-1 3.5-3 4" fill="none" stroke="currentColor" strokeWidth="1.5"/>
      <g stroke="#3b82f6" strokeWidth="0.8" fill="none" strokeLinecap="round">
        <path d="M10 16q-1-3 1-5"/>
        <path d="M10 16q1-3 3-4"/>
        <path d="M10 16q2-2 4-2.5"/>
        <path d="M10 16q-2-1.5-2-4"/>
        <path d="M10 16q-2.5-.5-3-2.5"/>
      </g>
    </svg>
  )
}
