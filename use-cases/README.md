# Use Case Index

Complete index of all use cases across domains, with implementation status.

**Status legend:**
- **Implemented** — fully working, spec-compliant
- **Implemented (diverges)** — working, accepted deviation from spec
- **Partial — action needed** — backend done, frontend work remaining
- **Not implemented — deferred** — postponed to a future release
- **Not implemented — nice to have** — low priority, not scheduled

## Admin Panel

### Authentication

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A01 | Login | Implemented | [UC-A01](./admin/UC-A01-login.md) |
| UC-A02 | Logout | Implemented | [UC-A02](./admin/UC-A02-logout.md) |
| UC-A03 | Change Password | Implemented | [UC-A03](./admin/UC-A03-change-password.md) |

### Member Management

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A10 | List Members | Implemented | [UC-A10](./admin/UC-A10-list-members.md) |
| UC-A11 | Create Member | Implemented | [UC-A11](./admin/UC-A11-create-member.md) |
| UC-A12 | Edit Member | Implemented | [UC-A12](./admin/UC-A12-edit-member.md) |
| UC-A13 | Assign RFID Card | Implemented (diverges) | [UC-A13](./admin/UC-A13-assign-rfid-card.md) |
| UC-A14 | Remove RFID Card | Implemented (diverges) | [UC-A14](./admin/UC-A14-remove-rfid-card.md) |
| UC-A15 | Deactivate Member | Implemented | [UC-A15](./admin/UC-A15-deactivate-member.md) |
| UC-A16 | Import Members (CSV) | Not implemented — nice to have | [UC-A16](./admin/UC-A16-import-members.md) |

### Tab Management

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A20 | View Tab | Implemented (diverges) | [UC-A20](./admin/UC-A20-view-tab.md) |
| UC-A21 | ~~Manual Purchase~~ | **Rejected 2026-08-08** — will not be built; kept as a tombstone | [UC-A21](./admin/UC-A21-manual-purchase.md) |
| UC-A22 | Export Transactions | Implemented (diverges) | [UC-A22](./admin/UC-A22-export-transactions.md) |
| UC-A23 | Storno | Not implemented | [UC-A23](./admin/UC-A23-storno.md) |

### Settlements

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A30 | Create Settlement (SEPA) | Implemented | [UC-A30](./admin/UC-A30-create-settlement.md) |
| UC-A31 | Download SEPA XML | Implemented | [UC-A31](./admin/UC-A31-download-sepa-xml.md) |
| UC-A32 | Download CSV | Implemented | [UC-A32](./admin/UC-A32-download-csv.md) |
| UC-A33 | Settlement History | Implemented | [UC-A33](./admin/UC-A33-settlement-history.md) |
| UC-A34 | Settlement Details | Implemented | [UC-A34](./admin/UC-A34-settlement-details.md) |
| UC-A35 | Manual Settlement | Implemented | [UC-A35](./admin/UC-A35-manual-settlement.md) |

### Product Management

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A40 | List Products | Implemented | [UC-A40](./admin/UC-A40-list-products.md) |
| UC-A41 | Create Product | Implemented | [UC-A41](./admin/UC-A41-create-product.md) |
| UC-A42 | Edit Product | Implemented | [UC-A42](./admin/UC-A42-edit-product.md) |
| UC-A43 | Deactivate Product | Implemented | [UC-A43](./admin/UC-A43-deactivate-product.md) |
| UC-A44 | Manage Categories | Implemented | [UC-A44](./admin/UC-A44-manage-categories.md) |

### Terminal Management

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A50 | List Terminals | Implemented | [UC-A50](./admin/UC-A50-list-terminals.md) |
| UC-A51 | Create Terminal | Implemented | [UC-A51](./admin/UC-A51-create-terminal.md) |
| UC-A52 | Update Terminal | Implemented | [UC-A52](./admin/UC-A52-update-terminal.md) |
| UC-A53 | Delete Terminal | Implemented | [UC-A53](./admin/UC-A53-delete-terminal.md) |
| UC-A54 | Rotate Terminal Token | Implemented | [UC-A54](./admin/UC-A54-rotate-terminal-token.md) |
| UC-A55 | View Terminal Details | Implemented | [UC-A55](./admin/UC-A55-view-terminal-details.md) |

### Reports

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A50 | Reports | Partial — action needed | [UC-A50](./admin/UC-A50-reports.md) |
| UC-A51 | Member Ranking | Partial — action needed | [UC-A51](./admin/UC-A51-member-ranking.md) |
| UC-A52 | Terminal Activity | Not implemented — action needed | [UC-A52](./admin/UC-A52-terminal-activity.md) |

### Settings

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A60 | Edit Organization | Implemented | [UC-A60](./admin/UC-A60-edit-organization.md) |
| UC-A61 | Manage Admins | Implemented | [UC-A61](./admin/UC-A61-manage-admins.md) |
| UC-A62 | Create Admin | Implemented | [UC-A62](./admin/UC-A62-create-admin.md) |
| UC-A63 | Reset Admin Password | Implemented | [UC-A63](./admin/UC-A63-reset-admin-password.md) |

### RFID Management

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A70 | Unassigned Cards | Not implemented — deferred | [UC-A70](./admin/UC-A70-unassigned-cards.md) |
| UC-A71 | Block Card | Not implemented — deferred | [UC-A71](./admin/UC-A71-block-card.md) |

### System

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-A80 | Dashboard | Partial — action needed | [UC-A80](./admin/UC-A80-dashboard.md) |
| UC-A81 | Audit Log | Implemented | [UC-A81](./admin/UC-A81-audit-log.md) |
| UC-A82 | Members Needing SEPA Data | Implemented (diverges) | [UC-A82](./admin/UC-A82-sepa-invalid-report.md) |

## Terminal App

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-T01 | Book Product to Tab | Implemented | [UC-T01](./terminal/UC-T01-book-product-to-tab.md) |
| UC-T02 | View Tab Balance | Implemented (diverges) | [UC-T02](./terminal/UC-T02-view-tab-balance.md) |
| UC-T03 | Change Language | Implemented (diverges) | [UC-T03](./terminal/UC-T03-change-language.md) |
| UC-T11 | Shopping Cart | Implemented | [UC-T11](./terminal/UC-T11-shopping-cart.md) |
| UC-T12 | Error Scenarios | Implemented | [UC-T12](./terminal/UC-T12-error-scenarios.md) |
| UC-T13 | Fetch Recent Transactions | Implemented (diverges) | [UC-T13](./terminal/UC-T13-fetch-recent-transactions.md) |
| UC-T14 | Update Balance on Sync | Implemented | [UC-T14](./terminal/UC-T14-update-balance-on-sync.md) |

## GDPR (DSGVO)

| ID | Name | GDPR Article | Status | Link |
|----|------|--------------|--------|------|
| UC-DSGVO-01 | Right to Access | Art. 15 | Partial — action needed | [UC-DSGVO-01](./dsgvo/uc-dsgvo-01-right-to-access.md) |
| UC-DSGVO-02 | Right to Erasure | Art. 17 | Partial — action needed | [UC-DSGVO-02](./dsgvo/uc-dsgvo-02-right-to-erasure.md) |
| UC-DSGVO-03 | Right to Rectification | Art. 16 | Implemented | [UC-DSGVO-03](./dsgvo/uc-dsgvo-03-right-to-rectification.md) |
| UC-DSGVO-04 | Right to Portability | Art. 20 | Implemented (diverges) | [UC-DSGVO-04](./dsgvo/uc-dsgvo-04-right-to-portability.md) |
| UC-DSGVO-05 | Right to Restriction | Art. 18 | Implemented | [UC-DSGVO-05](./dsgvo/uc-dsgvo-05-right-to-restriction.md) |
| UC-DSGVO-06 | Audit Log Access | Art. 30 | Implemented | [UC-DSGVO-06](./dsgvo/uc-dsgvo-06-audit-log-access.md) |

## SEPA

| ID | Name | Status | Link |
|----|------|--------|------|
| UC-SEPA-01 | Configuration Setup | Implemented | [UC-SEPA-01](./sepa/uc-sepa-01-config-setup.md) |
| UC-SEPA-02 | Configuration Update | Implemented | [UC-SEPA-02](./sepa/uc-sepa-02-config-update.md) |
| UC-SEPA-03 | Member IBAN Management | Implemented | [UC-SEPA-03](./sepa/uc-sepa-03-member-iban.md) |
| UC-SEPA-04 | Mandate Reference Management | Implemented | [UC-SEPA-04](./sepa/uc-sepa-04-mandate-reference.md) |
| UC-SEPA-05 | Settlement Creation | Implemented (diverges) | [UC-SEPA-05](./sepa/uc-sepa-05-settlement-create.md) |
| UC-SEPA-06 | Settlement Preview | Implemented | [UC-SEPA-06](./sepa/uc-sepa-06-settlement-preview.md) |
| UC-SEPA-07 | Settlement Finalization | Implemented (diverges) | [UC-SEPA-07](./sepa/uc-sepa-07-settlement-finalize.md) |
| UC-SEPA-08 | SEPA XML Export | Implemented | [UC-SEPA-08](./sepa/uc-sepa-08-xml-export.md) |
| UC-SEPA-09 | CSV Export | Implemented | [UC-SEPA-09](./sepa/uc-sepa-09-csv-export.md) |

## Summary

| Domain | Total | Implemented | Diverges | Partial | Not Impl. |
|--------|-------|-------------|----------|---------|-----------|
| Admin Panel | 43 | 32 | 5 | 4 | 7 |
| Terminal | 7 | 4 | 3 | 0 | 0 |
| GDPR | 6 | 3 | 1 | 2 | 0 |
| SEPA | 9 | 7 | 2 | 0 | 0 |
| **Total** | **65** | **46** | **11** | **6** | **7** |

Action items for partially/not implemented use cases are tracked in [plans/action-items-use-cases.md](../plans/action-items-use-cases.md).
