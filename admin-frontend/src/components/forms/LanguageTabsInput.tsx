import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { theme } from '../../styles/design-system';
import { tableColors } from '../../styles/tableTokens';
import { FieldLabel, type FieldRequirement } from './FieldLabel';

interface LanguageTabsInputProps {
  values: { de: string; en: string };
  onChange: (values: { de: string; en: string }) => void;
  label: string;
  placeholder?: string;
  /**
   * Which requirement tier the field is in (#629), replacing a `required` flag
   * that rendered its own `*` on top of whatever the call site had already
   * appended to `label`.
   */
  requirement?: FieldRequirement;
  /** `required`: whether at least one language carries a name. */
  satisfied?: boolean;
  multiline?: boolean;
  testIdPrefix?: string;
}

const LANGUAGES = ['de', 'en'] as const;

export function LanguageTabsInput({
  values,
  onChange,
  label,
  placeholder,
  requirement = 'required',
  satisfied = false,
  multiline = false,
  testIdPrefix = 'lang-input',
}: LanguageTabsInputProps) {
  const { t } = useTranslation();
  const [activeTab, setActiveTab] = useState<'de' | 'en'>('de');

  const handleChange = (lang: 'de' | 'en', value: string) => {
    onChange({ ...values, [lang]: value });
  };

  const hasContent = (lang: 'de' | 'en') => values[lang]?.trim().length > 0;

  return (
    <div data-testid={`${testIdPrefix}-container`}>
      <FieldLabel
        label={label}
        requirement={requirement}
        satisfied={satisfied}
        testId={`${testIdPrefix}-label`}
      />

      {/* Language Tabs */}
      <div style={{ display: 'flex', gap: '4px', marginBottom: '8px' }}>
        {LANGUAGES.map((lang) => (
          <button
            key={lang}
            type="button"
            onClick={() => setActiveTab(lang)}
            data-testid={`${testIdPrefix}-tab-${lang}`}
            style={{
              padding: '4px 12px',
              border: '1px solid var(--color-border)',
              borderRadius: '4px',
              background: activeTab === lang ? 'var(--color-primary)' : 'var(--color-bg-secondary)',
              color: activeTab === lang ? 'white' : 'var(--color-text-primary)',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '4px',
            }}
          >
            {t(`languages.${lang}`)}
            {hasContent(lang) && (
              <span
                style={{
                  width: '6px',
                  height: '6px',
                  borderRadius: '50%',
                  background: activeTab === lang ? 'white' : 'var(--color-success)',
                }}
              />
            )}
          </button>
        ))}
      </div>

      {/* Input Field */}
      {multiline ? (
        <textarea
          value={values[activeTab]}
          onChange={(e) => handleChange(activeTab, e.target.value)}
          placeholder={placeholder}
          data-testid={`${testIdPrefix}-input-${activeTab}`}
          style={{
            width: '100%',
            minHeight: '80px',
            padding: '10px 12px',
            border: `1px solid ${theme.colors.border.muted}`,
            borderRadius: '6px',
            backgroundColor: theme.colors.bg.inputAlt,
            color: tableColors.cellText,
            fontSize: '14px',
            boxSizing: 'border-box',
            resize: 'vertical',
          }}
        />
      ) : (
        <input
          type="text"
          value={values[activeTab]}
          onChange={(e) => handleChange(activeTab, e.target.value)}
          placeholder={placeholder}
          data-testid={`${testIdPrefix}-input-${activeTab}`}
          style={{
            width: '100%',
            padding: '10px 12px',
            border: `1px solid ${theme.colors.border.muted}`,
            borderRadius: '6px',
            backgroundColor: theme.colors.bg.inputAlt,
            color: tableColors.cellText,
            fontSize: '14px',
            boxSizing: 'border-box',
          }}
        />
      )}
    </div>
  );
}
