# Admin Panel Use Cases

Use cases for the Admin Panel web application (React SPA for desktop browsers).

## System Purpose

The admin panel provides management and accounting functions for the bar system:
- Member management (CRUD, RFID assignment, SEPA data)
- Product management (CRUD, categories, pricing)
- Transaction oversight and stornos
- Settlement creation and SEPA export
- Reporting and audit logging

**Administrative functions only.** Members interact with the separate Terminal application.

## Actor

| Actor | Description |
|-------|-------------|
| **Admin** | Organization member with admin credentials |

## Use Case Index

### Authentication

| ID | Name | Description |
|----|------|-------------|
| [UC-A01](./UC-A01-login.md) | Login | Authenticate and start session |
| [UC-A02](./UC-A02-logout.md) | Logout | End session |
| [UC-A03](./UC-A03-change-password.md) | Change Password | Update own password (step-up re-auth) |
| [UC-A04](./UC-A04-change-email.md) | Change Email | Update own login address (step-up re-auth) |

### Member Management

| ID | Name | Description |
|----|------|-------------|
| [UC-A10](./UC-A10-list-members.md) | List Members | Browse and filter member list |
| [UC-A11](./UC-A11-create-member.md) | Create Member | Add new member |
| [UC-A12](./UC-A12-edit-member.md) | Edit Member | Update member details |
| [UC-A13](./UC-A13-assign-rfid-card.md) | Assign RFID Card | Link card to member |
| [UC-A14](./UC-A14-remove-rfid-card.md) | Remove RFID Card | Unlink card from member |
| [UC-A15](./UC-A15-deactivate-member.md) | Deactivate Member | Disable member account |

### Tab Management

| ID | Name | Description |
|----|------|-------------|
| [UC-A20](./UC-A20-view-tab.md) | View Tab | See member balance and history |
| [UC-A21](./UC-A21-manual-purchase.md) | ~~Manual Purchase~~ | **Rejected** — the answer to bar service away from the fridge is a terminal there, not a typed amount later |
| [UC-A23](./UC-A23-storno.md) | Storno | Reverse one transaction in full |
| [UC-A22](./UC-A22-export-transactions.md) | Export Transactions | Download transaction CSV |

### Settlements

| ID | Name | Description |
|----|------|-------------|
| [UC-A30](./UC-A30-create-settlement.md) | Create Settlement (SEPA) | Generate SEPA collection for eligible members |
| [UC-A31](./UC-A31-download-sepa-xml.md) | Download SEPA XML | Export for bank upload |
| [UC-A32](./UC-A32-download-csv.md) | Download CSV | Export for verification |
| [UC-A33](./UC-A33-settlement-history.md) | Settlement History | List past settlements |
| [UC-A34](./UC-A34-settlement-details.md) | Settlement Details | View settlement breakdown |
| [UC-A35](./UC-A35-manual-settlement.md) | Manual Settlement | Settle without SEPA (bank transfer, write-off) |

### Product Management

| ID | Name | Description |
|----|------|-------------|
| [UC-A40](./UC-A40-list-products.md) | List Products | Browse product catalog |
| [UC-A41](./UC-A41-create-product.md) | Create Product | Add new product |
| [UC-A42](./UC-A42-edit-product.md) | Edit Product | Update product details |
| [UC-A43](./UC-A43-deactivate-product.md) | Deactivate Product | Hide from terminal |
| [UC-A44](./UC-A44-manage-categories.md) | Manage Categories | Organize product categories |

### Reports

| ID | Name | Description |
|----|------|-------------|
| [UC-A50](./UC-A50-reports.md) | Reports | Unified reporting (revenue, consumption, trends) |
| [UC-A51](./UC-A51-member-ranking.md) | Member Ranking | Removed — see the use case for why |
| [UC-A52](./UC-A52-terminal-activity.md) | Terminal Activity | Transaction sessions |

### Settings

| ID | Name | Description |
|----|------|-------------|
| [UC-A60](./UC-A60-edit-organization.md) | Edit Organization | SEPA configuration |
| [UC-A61](./UC-A61-manage-admins.md) | Manage Admins | Admin user list |
| [UC-A62](./UC-A62-create-admin.md) | Create Admin | Add admin user |
| [UC-A63](./UC-A63-reset-admin-password.md) | Reset Admin Password | Generate new password |
| [UC-A66](./UC-A66-credit-limit-digest.md) | Near-Limit Digest | One scheduled mail listing members close to their Deckel limit |

### System

| ID | Name | Description |
|----|------|-------------|
| [UC-A80](./UC-A80-dashboard.md) | Dashboard | System overview |
| [UC-A81](./UC-A81-audit-log.md) | Audit Log | Activity history |
| [UC-A82](./UC-A82-sepa-invalid-report.md) | SEPA Issues | Members needing SEPA data |

## Non-Functional Requirements

| Requirement | Value |
|-------------|-------|
| Browser Support | Chrome, Firefox, Safari (current) |
| Layout | Desktop-optimized, tablet usable |
| Session Timeout | 24 hours |
| Transport | HTTPS required |
| Languages | German, English (i18n) |

## Use Case Format

Each use case follows this structure:

- **Actor**: Who performs the action
- **Preconditions**: Required state before starting
- **Trigger**: What initiates the use case
- **Main Flow**: Step-by-step happy path
- **Postconditions**: Expected state after completion
- **Variants**: Alternative flows
- **Error Cases**: Failure scenarios
- **Test Derivation**: Suggested test cases
