import { describe, expect, it } from 'vitest'
import { theme, withAlpha } from './design-system'

describe('withAlpha', () => {
  it('composes a black hex color with an alpha channel', () => {
    expect(withAlpha('#000000', 0.5)).toBe('rgba(0, 0, 0, 0.5)')
  })

  it('composes a white hex color with an alpha channel', () => {
    expect(withAlpha('#ffffff', 0.06)).toBe('rgba(255, 255, 255, 0.06)')
  })

  it('accepts a hex color with mixed case', () => {
    expect(withAlpha('#3B82f6', 0.15)).toBe('rgba(59, 130, 246, 0.15)')
  })
})

describe('theme.colors.bg.surfaceSubtle', () => {
  it('exposes a canonical faint white surface-fill tint', () => {
    expect(theme.colors.bg.surfaceSubtle).toBe('rgba(255, 255, 255, 0.04)')
  })
})

describe('theme.overlay', () => {
  it('exposes a single canonical modal backdrop token', () => {
    expect(theme.overlay.backdrop).toBe('rgba(0, 0, 0, 0.5)')
  })
})

describe('theme.mobileCard', () => {
  it('exposes canonical background and border tints', () => {
    expect(theme.mobileCard.bg).toBe('rgba(255, 255, 255, 0.03)')
    expect(theme.mobileCard.border).toBe('rgba(255, 255, 255, 0.06)')
  })
})

describe('theme.pillButton', () => {
  it('exposes canonical idle background and text tints', () => {
    expect(theme.pillButton.idleBg).toBe('rgba(255, 255, 255, 0.06)')
    expect(theme.pillButton.idleText).toBe('rgba(255, 255, 255, 0.55)')
  })
})

describe('theme.badges.danger', () => {
  it('exposes a canonical border tint alongside the existing bg tint', () => {
    expect(theme.badges.danger.border).toBe('rgba(239, 68, 68, 0.3)')
  })
})

describe('theme.activeTint', () => {
  it('exposes a canonical primary-blue active-state tint', () => {
    expect(theme.activeTint.primary).toBe('rgba(59, 130, 246, 0.15)')
  })

  it('exposes a stronger primary-blue tint variant', () => {
    expect(theme.activeTint.primaryStrong).toBe('rgba(59, 130, 246, 0.2)')
  })

  it('exposes an even stronger primary-blue border tint variant', () => {
    expect(theme.activeTint.primaryBorder).toBe('rgba(59, 130, 246, 0.5)')
  })

  it('exposes the profile-nav-item active tint', () => {
    expect(theme.activeTint.profileActive).toBe('rgba(59, 130, 246, 0.25)')
  })
})

describe('theme.badges.success', () => {
  it('exposes a canonical border tint alongside the existing bg tint', () => {
    expect(theme.badges.success.border).toBe('rgba(34, 197, 94, 0.3)')
  })
})

describe('theme.colors.border.subtle', () => {
  it('exposes a canonical subtle white border/divider tint', () => {
    expect(theme.colors.border.subtle).toBe('rgba(255, 255, 255, 0.08)')
  })
})

describe('theme.colors.border.slate', () => {
  it('exposes a canonical translucent slate border tint', () => {
    expect(theme.colors.border.slate).toBe('rgba(71, 85, 105, 0.4)')
  })
})

describe('theme.colors.text.label', () => {
  it('exposes a canonical uppercase group-label text tint', () => {
    expect(theme.colors.text.label).toBe('rgba(255, 255, 255, 0.35)')
  })
})

describe('theme.shadows.modalStrong', () => {
  it('exposes a canonical second modal shadow shape', () => {
    expect(theme.shadows.modalStrong).toBe('0 25px 50px rgba(0, 0, 0, 0.5)')
  })
})

describe('theme.shadows.dropdown', () => {
  it('exposes a canonical dropdown/popover shadow', () => {
    expect(theme.shadows.dropdown).toBe('0 10px 40px rgba(0, 0, 0, 0.4)')
  })
})
