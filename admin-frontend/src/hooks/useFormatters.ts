import { useTranslation } from 'react-i18next';
import { formatPrice, formatVolume, formatDate, formatDateTime } from '../styles/design-system';
import { getIntlLocale } from '../utils/i18n-helpers';
import { parseApiDate } from '../utils/dates';

/**
 * Hook that provides locale-aware formatting functions.
 * Uses the current i18n language for all formatting.
 */
export function useFormatters() {
  const { i18n, t } = useTranslation();
  const intlLocale = getIntlLocale(i18n.language);

  return {
    /**
     * Format a price in cents to currency string
     */
    formatPrice: (cents: number) => formatPrice(cents, intlLocale),

    /**
     * A product's size in whole millilitres, written for the panel's language.
     *
     * `500` → `0,5 l` in German, `0.5 l` in English; below 100 ml it reads in
     * millilitres (`20 ml`), because `0,02 l` says less. `null` — the product
     * has no size at all — formats to an empty string, so a caller can
     * concatenate without a branch (ADR-0056).
     *
     * Never format a volume by hand: the rule is shared with the backend and
     * the terminal through `api/fixtures/volume-format.json`.
     */
    formatVolume: (millilitres: number | null | undefined) =>
      millilitres === null || millilitres === undefined ? '' : formatVolume(millilitres, intlLocale),

    /**
     * Format a date string to localized date
     */
    formatDate: (date: string) => formatDate(date, intlLocale),

    /**
     * Format a date string to localized date with time
     */
    formatDateTime: (date: string) => formatDateTime(date, intlLocale),

    /**
     * Format a date with relative labels (Today, Yesterday, or full date)
     *
     * Both sides of the comparison are local midnight. Parsing the value with
     * `new Date()` instead would anchor a date-only string at UTC midnight, and
     * truncating *that* with `setHours` moves it to the previous calendar day
     * for users west of Greenwich — a settlement dated today read "Yesterday".
     */
    formatRelativeDate: (dateString: string) => {
      if (!dateString) return t('dates.never');

      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);

      const date = parseApiDate(dateString);
      date.setHours(0, 0, 0, 0);

      if (date.getTime() === today.getTime()) {
        return t('dates.today');
      }
      if (date.getTime() === yesterday.getTime()) {
        return t('dates.yesterday');
      }

      return formatDate(dateString, intlLocale);
    },

    /**
     * Get the current Intl locale string (e.g., 'de-DE', 'en-GB')
     */
    intlLocale,

    /**
     * Get the current i18n language code (e.g., 'de', 'en')
     */
    language: i18n.language,
  };
}
