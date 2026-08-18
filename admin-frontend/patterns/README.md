# Admin Frontend Patterns

This directory contains design patterns and best practices for the Admin Frontend React SPA.

## Available Patterns

### [Test IDs Pattern](./test-ids.md)
**Purpose**: Establish reliable, semantic selectors for E2E tests

- ✅ Naming conventions (kebab-case, semantic hierarchy)
- ✅ Implementation patterns for common UI components
- ✅ Best practices for adding test IDs during development
- ✅ Playwright tips and custom locators
- ✅ Complete examples for pages, forms, tables, modals, and state management
- ✅ Checklist for implementing test IDs

**When to use**: When building any component that needs E2E test coverage

**Quick Start**:
```typescript
// Component
<button data-testid="members-create-button">Create Member</button>

// Test
const button = page.getByTestId('members-create-button')
await button.click()
```

### [Table Implementation Pattern](./table-implementation.md)
**Purpose**: Build a list page — query state, controls, table, pagination

- ✅ `useListQuery` for page/sort/filters/search, debounce, abort and page clamping
- ✅ Shared control components (`MobileFilterRow`, `PaginationToolbar`, `SortableTableHeader`)
- ✅ Loading, empty and error states
- ✅ Common pitfalls, including the hand-rolled list state this replaced (#121)

**When to use**: When building or changing any paginated list page

**Quick Start**:
```typescript
const list = useListQuery<Item, ItemFilters, ItemSortKey>({
  initialFilters: { status: 'all' },
  initialSortKey: 'created_at',
  fetcher: async ({ page, pageSize, filters, signal }) => { /* ... */ },
})
```

### [Role-Aware Navigation](./role-visibility.md)
**Purpose**: show each admin office only the sections it can open (ADR-0044)

- ✅ `SECTION_ROLES` — the section→roles table, default-deny like the server's
- ✅ Per-role landing, and why it reads the roles off the auth result
- ✅ The named `insufficient_role` refusal screen, and the mid-session case
- ✅ The completeness test that fails when a nav entry is left unclassified

**When to use**: when adding a page, a nav entry or a role

**Quick Start**:
```typescript
// src/utils/adminRoles.ts — a page with no entry here is admin-only
'/products': BAR,
```

---

### [Component Patterns](./components.md)
**Purpose**: Reference for the reusable UI components available to pages

**When to use**: Before writing a new component — check whether one exists

**Every page starts the same way** (#375) — `PageHeader` owns the title/actions
grid and `MainLayout` owns the page gutter, so a page adds neither:
```typescript
<div data-testid="products-page">
  <PageHeader
    title={t('products.title')}
    actions={<PageActionButton data-testid="products-create-button" onClick={openCreateModal} icon={<PlusIcon size={18} />}>{t('products.createProduct')}</PageActionButton>}
  />
  {/* toolbar, table, pagination */}
</div>
```

---

### [Data Fetching Pattern](./data-fetching.md)
**Purpose**: Render the answer to the question the page is currently asking, and only that one

- ✅ Request cancellation with `useLatestRequest` and the orval `signal` option
- ✅ Stale-response guards for search debounces, auto-refresh and tab switches
- ✅ Why `signal.aborted` beats an `isMounted` ref and beats catching the abort error
- ✅ Spinner ownership when a mutation reload supersedes a loader effect
- ✅ Checklist and anti-patterns

**When to use**: When building any page that fetches on a filter, search, sort, page or interval

**Quick Start**:
```typescript
const listRequest = useLatestRequest()

useEffect(() => {
  const signal = listRequest.next()
  const timer = setTimeout(() => loadItems(signal), search ? 500 : 0)
  return () => { clearTimeout(timer); listRequest.abort() }
}, [page, search])
```

> On a **list page**, `useListQuery` already owns this slot — use the hook and
> pass its `signal` through to the client. Reach for `useLatestRequest`
> directly for the streams it does not cover: a page's second, independent
> fetch, an auto-refresh interval, or a tab switch.

---
---

## Pattern Development Guidelines

When creating new patterns:

1. **Document the pattern** with clear title and purpose
2. **Provide naming conventions** or structure
3. **Include implementation examples** with code snippets
4. **Show before/after** (good vs. bad examples)
5. **Add a quick reference** or checklist
6. **Reference related patterns** for context
7. **Keep it concise** but comprehensive

---

## Related Resources

- **[CLAUDE.md](../../../CLAUDE.md)** — Project instructions and overview
- **[technologies.md](../technologies.md)** — Tech stack and dependencies
- **[E2E Testing Patterns](../../../e2etests/patterns/)** — Backend/API testing patterns
- **[Backend Patterns](../../../backend/patterns/)** — Server-side code patterns

---

## Contributing New Patterns

To add a new pattern:

1. Create a new markdown file: `pattern-name.md`
2. Follow the structure of [Test IDs Pattern](./test-ids.md)
3. Update this README.md with a link to the new pattern
4. Link the pattern in appropriate documentation (CLAUDE.md, technologies.md, etc.)
5. Add version history at the bottom of the pattern file

---

## Pattern Versions

| Pattern | Version | Status | Created |
|---------|---------|--------|---------|
| [Test IDs](./test-ids.md) | 1.0 | Active | 2026-01-26 |
| [Table Implementation](./table-implementation.md) | 2.0 | Active | 2026-01-26 |
| [Components](./components.md) | 1.0 | Active | 2026-01-26 |
| [Data Fetching](./data-fetching.md) | 1.0 | Active | 2026-08-08 |
