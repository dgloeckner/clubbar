# ADR-0010: Mandate Lifecycle and Retention

**Status**: Superseded

**Superseded by**: ADR-0006 (simplified mandate reference strategy - no lifecycle tracking)

**Date**: 2025-01-23

---

## Summary

This ADR is superseded. Mandate lifecycle management (expiry, revocation, signature dates, compliance tracking) is OUT OF SCOPE for the system.

**Current approach** ([ADR-0006](./0006-sepa-mandate-reference-strategy.md)): The system only tracks a simple editable `mandate_reference` field per member. All mandate-related compliance, lifecycle management, and document retention is performed outside the system (manual processes, separate document management systems, compliance procedures).

### Why Superseded

The original ADR proposed comprehensive mandate lifecycle tracking within the system (CREATED → ACTIVE → EXPIRED → REVOKED → ARCHIVED states, 36-month expiry checking, first-use tracking). This complexity was deemed over-engineered for small organizations (member bars/clubs) where:

1. **Manual mandate management is standard**: Physical mandates are collected and stored outside the system
2. **Simplicity wins**: External tracking (spreadsheet, document folder) is sufficient
3. **System focus**: Transactions and settlements are core; mandate administration is secondary
4. **SEPA compliance**: Only the mandate reference is needed in XML export (not lifecycle metadata)

---

## Recommendation

See **[ADR-0006: SEPA Mandate Reference Strategy](./0006-sepa-mandate-reference-strategy.md)** for the current lightweight approach.

### Key Differences from This ADR

| Aspect | Original (ADR-0010) | Current (ADR-0006) |
|--------|--------|--------|
| Lifecycle tracking | In-system (CREATED/ACTIVE/EXPIRED/REVOKED/ARCHIVED) | No lifecycle tracking |
| Expiry checking | Automatic at 36 months of inactivity | Admin responsibility (external) |
| Revocation handling | mandate_active flag checked on settlement | Admin responsibility (external) |
| First-use tracking | mandate_first_used_at timestamp | Not tracked |
| Audit logging | Full state machine transitions | Only mandate_reference changes |
| Admin dashboard | Mandate health widget (active/expired/revoked counts) | Not provided |
| Document retention | PSD2 (14 months) calculated in system | Admin tracks separately (external) |

---

## References

**Original retention requirements** (for context; now handled outside system):

| Document | Retention | Basis |
|----------|-----------|-------|
| Original mandate | 14 months after last use | PSD2 (Payment Services Directive 2) |
| Settlement records | 10 years | § 147 AO (German tax code) |
| Audit logs | 10 years | § 147 AO + GDPR |

These requirements are still valid but are now the organization's responsibility to manage manually.

