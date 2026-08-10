module.exports = {
  root: true,
  env: { browser: true, es2020: true },
  extends: [
    'eslint:recommended',
    'plugin:@typescript-eslint/recommended',
    'plugin:react-hooks/recommended',
  ],
  ignorePatterns: ['dist', '.eslintrc.cjs'],
  parser: '@typescript-eslint/parser',
  plugins: ['react-refresh'],
  rules: {
    'react-refresh/only-export-components': [
      'warn',
      { allowConstantExport: true },
    ],
    '@typescript-eslint/no-explicit-any': 'warn',
    'no-console': ['warn', { allow: ['warn', 'error'] }],
  },
  overrides: [
    // Raw hex color literals bypass the design system (#124) and drift from
    // the token values over time. Enforced only for files already migrated
    // onto `theme`/`tableColors` — add a file here once it is hex-free, and
    // add any new color to `src/styles/design-system.ts` (theme.colors) or
    // `src/styles/tableTokens.ts` rather than reintroducing a literal.
    {
      files: [
        'src/pages/LoginPage.tsx',
        'src/pages/ProductsPage.tsx',
        'src/components/common/Button.tsx',
        'src/components/common/PillActionButton.tsx',
        'src/components/forms/CategorySelect.tsx',
        'src/components/forms/IconSelect.tsx',
        'src/components/forms/LanguageSelector.tsx',
        'src/components/forms/LanguageTabsInput.tsx',
        'src/components/forms/PillFilter.tsx',
        'src/components/tables/CategoryFilter.tsx',
        'src/components/tables/ListLoadingOverlay.tsx',
        'src/components/tables/PaginationToolbar.tsx',
        'src/components/members/MembersTabs.tsx',
        'src/pages/SettlementsPage.tsx',
        'src/pages/NewSettlementPage.tsx',
        'src/components/modals/UndoSettlementDialog.tsx',
        'src/pages/MembersPage.tsx',
        'src/pages/ExcludedFromCollectionPage.tsx',
      ],
      rules: {
        'no-restricted-syntax': [
          'error',
          {
            selector: "Literal[value=/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/]",
            message:
              'Raw hex color literals are banned in migrated files (#124) — add a token to theme.colors (design-system.ts) or tableTokens.ts and reference it instead.',
          },
        ],
      },
    },
  ],
}
