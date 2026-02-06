/**
 * Get localized name from a multilingual names object.
 * Falls back through: requested locale -> 'de' -> 'en' -> first available -> empty string
 */
export function getLocalizedName(
  names: Record<string, string> | undefined | null,
  locale: string
): string {
  if (!names) return '';

  // Try requested locale first
  if (names[locale]) return names[locale];

  // Fallback chain: de -> en -> first available
  if (names['de']) return names['de'];
  if (names['en']) return names['en'];

  // Return first available value
  const values = Object.values(names);
  return values.length > 0 ? values[0] : '';
}

/**
 * Check if a multilingual names object has at least one non-empty value
 */
export function hasAnyName(names: Record<string, string> | undefined | null): boolean {
  if (!names) return false;
  return Object.values(names).some(v => v && v.trim().length > 0);
}

/**
 * Get the locale string for Intl APIs (de -> de-DE, en -> en-GB)
 */
export function getIntlLocale(lang: string): string {
  switch (lang) {
    case 'de':
      return 'de-DE';
    case 'en':
      return 'en-GB';
    default:
      return 'de-DE';
  }
}
