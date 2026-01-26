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
