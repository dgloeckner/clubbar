import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

// Import translations directly for bundling
import de from '../../public/locales/de.json';
import en from '../../public/locales/en.json';

// Same key session.ts uses for the admin's `locale` field, so a login/logout/
// profile-save writes one value instead of two that can drift apart (#134).
const LOCALE_STORAGE_KEY = 'locale';

// Get initial language from localStorage or default to German
function getInitialLanguage(): string {
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
  if (stored && ['de', 'en'].includes(stored)) {
    return stored;
  }
  return 'de';
}

i18n
  .use(initReactI18next)
  .init({
    resources: {
      de: { translation: de },
      en: { translation: en },
    },
    lng: getInitialLanguage(),
    fallbackLng: 'de',
    interpolation: {
      escapeValue: false, // React already escapes values
    },
    react: {
      useSuspense: false, // Avoid suspense for simpler setup
    },
  });

// Helper to change language and persist to localStorage
export function changeLanguage(lang: string): void {
  if (['de', 'en'].includes(lang)) {
    i18n.changeLanguage(lang);
    localStorage.setItem(LOCALE_STORAGE_KEY, lang);
  }
}

// Helper to get current language
export function getCurrentLanguage(): string {
  return i18n.language || 'de';
}

// Export the configured instance
export default i18n;
