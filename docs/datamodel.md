# Data Model

This document defines the complete data model for Club Bar, covering both backend (MariaDB) and frontend (SQLite cache) entities.

## Overview

```
Backend (MariaDB)                    Terminal Frontend (SQLite)
─────────────────                    ────────────────────────────
members                    ──sync──► members_cache (read-only)
products                   ──sync──► products_cache (read-only)
categories                 ──sync──► categories_cache (read-only)
transactions               ◄─sync──  transactions_local (write)
settlements
settlement_items
admin_users
audit_log
sepa_config
terminals
unknown_card_scans
```

---

## Backend Entities

### members

Member accounts for the organization. Contains personal and payment data.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | BINARY(16) | Yes | UUID primary key | Permanent identifier; never changes even after anonymization | UUID v4 format |
| card_uid | VARCHAR(20) | No | RFID/NFC card identifier | Enables terminal identification; NULL if no card assigned | 8-20 hex chars, uppercase, UNIQUE |
| first_name | VARCHAR(100) | No | Given name | Display and identification; NULL after GDPR anonymization | Max 100 chars |
| last_name | VARCHAR(100) | No | Family name | Display and identification; NULL after GDPR anonymization | Max 100 chars |
| email | VARCHAR(255) | No | Contact email | Communication; not used for authentication | Valid email format |
| preferred_language | VARCHAR(10) | Yes | ISO 639-1 language code | Terminal displays products in this language | From enabled_languages config; default: organization default |
| iban | VARCHAR(34) | No* | Bank account for SEPA | Required for terminal access and SEPA collection; NULL only for legacy/anonymized members | ISO 13616 IBAN format + mod-97 checksum |
| mandate_reference | VARCHAR(35) | No* | SEPA mandate identifier | Required for terminal access; default = UUID without hyphens; NULL only for legacy/anonymized members | SEPA charset: 0-9 a-z A-Z + ? / - : ( ) . , ' |
| mandate_signed_at | DATE | No* | Mandate signature date | Required when IBAN set; NULL only for legacy/anonymized members | Date, <= today |
| is_active | BOOLEAN | Yes | Account status | Inactive members cannot use terminal or be included in settlements | Default: true |
| deleted_at | DATETIME | No | GDPR anonymization timestamp | NULL = active; set = anonymized | Set by system only |
| created_at | DATETIME | Yes | Record creation | Audit trail | Auto-set |
| updated_at | DATETIME | Yes | Last modification | Sync delta detection | Auto-updated |

**Indexes**: `card_uid` (UNIQUE), `iban`, `is_active`, `updated_at`

**\* SEPA Fields**: Nullable at database level (for GDPR anonymization and legacy data), but required at application level for member creation. Members without valid IBAN + mandate_reference cannot use the terminal. See [ADR-0020](../adr/0020-sepa-mandate-requirement-terminal-access.md).

**GDPR Anonymization**: Sets first_name, last_name, email, iban, mandate_reference to NULL; card_uid to "ANONYMOUS-{uuid}"; is_active to false; deleted_at to timestamp.

---

### products

Product catalog with multilingual names.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | BINARY(16) | Yes | UUID primary key | Referenced by transactions | UUID v4 format |
| category_id | BINARY(16) | Yes | FK to categories | Groups products in terminal UI | Valid category reference |
| names | JSON | Yes | Multilingual names | Display in terminal/admin; keyed by ISO 639-1 | At least one language; e.g., `{"en": "Beer 0.5L", "de": "Bier 0,5L"}` |
| descriptions | JSON | No | Multilingual descriptions | Optional product details | Same structure as names |
| price_cents | INT | Yes | Price in cents | Transaction amount calculation | > 0; max 999999 (€9,999.99) |
| is_active | BOOLEAN | Yes | Availability status | Inactive products hidden on terminal | Default: true |
| created_at | DATETIME | Yes | Record creation | Audit trail | Auto-set |
| updated_at | DATETIME | Yes | Last modification | Sync delta detection | Auto-updated |

**Indexes**: `category_id`, `is_active`, `updated_at`

**Price Changes**: New price applies to new transactions only. Historical transactions retain original amount_cents.

---

### categories

Product categories for organization.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | BINARY(16) | Yes | UUID primary key | Referenced by products | UUID v4 format |
| names | JSON | Yes | Multilingual names | Display as tab labels in terminal/admin | At least one language; e.g., `{"en": "Beverages", "de": "Getränke"}` |
| display_order | INT | Yes | Sort position | Terminal tab order | >= 0 |
| is_active | BOOLEAN | Yes | Availability status | Inactive categories hidden on terminal | Default: true |
| created_at | DATETIME | Yes | Record creation | Audit trail | Auto-set |
| updated_at | DATETIME | Yes | Last modification | Sync delta detection | Auto-updated |

**Indexes**: `display_order`, `is_active`, `updated_at`

**Localization**: Same pattern as products. Terminal displays category name based on member's `preferred_language` with fallback to organization default.

**Deactivation**: Inactive categories are hidden on terminal. Products in inactive categories are also hidden (even if product itself is active).

---

### transactions

Immutable transaction log. Append-only; never updated or deleted.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | BINARY(16) | Yes | UUID primary key | Idempotency key for sync deduplication | UUID v4; client-generated |
| member_id | BINARY(16) | Yes | FK to members | Links transaction to member account | Valid member reference |
| product_id | BINARY(16) | No | FK to products | NULL for stornos and payouts | Valid product reference if set |
| amount_cents | INT | Yes | Amount in cents | Positive = charge; negative = credit/reversal | Non-zero; -999999 to +999999 |
| transaction_type | ENUM | Yes | Type of transaction | Categorization for reporting | 'purchase', 'correction' |
| notes | VARCHAR(500) | No | Reason/description | Required for manual entries; optional for others | Max 500 chars |
| related_transaction_id | BINARY(16) | No | FK to transactions | Optional link to original transaction being corrected | Valid transaction reference or NULL |
| created_by_terminal_id | BINARY(16) | No | FK to terminals | Audit: which terminal created | Set for terminal transactions |
| created_by_admin_id | BINARY(16) | No | FK to admin_users | Audit: which admin created | Set for manual entries |
| created_at | DATETIME | Yes | Transaction timestamp | Immutable; actual time of transaction | Client-provided or server-set |
| synced | BOOLEAN | No | Sync status (terminal only) | Terminal-side flag; not in backend | Default: false |

**Indexes**: `member_id`, `product_id`, `transaction_type`, `created_at`, `related_transaction_id`

**Immutability**: No UPDATE or DELETE operations. Corrections via reversal transactions.

---

### settlements

Settlement records for SEPA collections and manual settlements.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | BINARY(16) | Yes | UUID primary key | Referenced by settlement_items | UUID v4 format |
| settlement_type | ENUM | Yes | Type of settlement | Determines processing rules | 'sepa', 'manual' |
| settlement_date | DATE | Yes | Creation date | When settlement was created | Auto-set to today |
| execution_date | DATE | No | SEPA execution date | Bank processing date; NULL for manual | >= settlement_date + 7 days (SEPA only) |
| period_start | DATE | No | Accounting period start | Optional; for reporting | <= period_end |
| period_end | DATE | No | Accounting period end | Optional; for reporting | >= period_start |
| sepa_message_id | VARCHAR(35) | No | SEPA XML message ID | Unique per XML export; NULL for manual | Auto-generated on first export |
| manual_reason | ENUM | No | Reason for manual settlement | Required if settlement_type = 'manual' | 'cash_payment', 'bank_transfer', 'other_payment', 'write_off', 'goodwill', 'correction', 'other' |
| total_amount_cents | INT | Yes | Total settlement amount | Sum of all settlement_items | Calculated; > 0 |
| member_count | INT | Yes | Number of members included | Statistics | Calculated; > 0 |
| is_cancelled | BOOLEAN | Yes | Cancellation flag | If true, transactions are unsettled | Default: false |
| cancelled_at | DATETIME | No | Cancellation timestamp | When settlement was cancelled | Set when cancelled |
| cancelled_by_admin_id | BINARY(16) | No | FK to admin_users | Who cancelled | Set when cancelled |
| exported_at | DATETIME | No | Last export timestamp | When CSV/XML was last downloaded | Updated on each export |
| notes | VARCHAR(500) | No | Admin notes | Required for manual (min 10 chars); description or comments | Max 500 chars |
| created_by_admin_id | BINARY(16) | Yes | FK to admin_users | Audit trail | Valid admin reference |
| created_at | DATETIME | Yes | Record creation | Audit trail | Auto-set |
| updated_at | DATETIME | Yes | Last modification | Change tracking | Auto-updated |

**Indexes**: `is_cancelled`, `settlement_date`, `execution_date`, `settlement_type`

**Settlement Types**:
- `sepa`: Standard SEPA Direct Debit settlement with XML export
- `manual`: Non-SEPA settlement (cash, bank transfer, write-off, etc.)

**Manual Settlement Reasons**:
- `cash_payment`: Member paid in cash
- `bank_transfer`: Member paid via manual bank transfer
- `other_payment`: Member paid via other method (PayPal, etc.)
- `write_off`: Debt written off as uncollectable
- `goodwill`: Balance cleared as goodwill gesture
- `correction`: Administrative correction
- `other`: Other reason (explain in notes)

**Cancellation**: Sets `is_cancelled = true`; linked transactions become unsettled again (available for future settlement).

**Export Tracking**: `exported_at` updated on each download; audit log entry created for each export.

---

### settlement_items

Links transactions to settlements (many-to-many).

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | INT | Yes | Auto-increment PK | Internal reference | Auto-generated |
| settlement_id | BINARY(16) | Yes | FK to settlements | Which settlement | Valid settlement reference |
| transaction_id | BINARY(16) | Yes | FK to transactions | Which transaction | Valid transaction reference |
| member_id | BINARY(16) | Yes | FK to members | Denormalized for queries | Valid member reference |
| amount_cents | INT | Yes | Member's total amount | Sum of member's transactions in this settlement | Calculated |

**Indexes**: `settlement_id`, `transaction_id` (UNIQUE pair), `member_id`

**Constraint**: Each transaction can only belong to one settlement.

---

### admin_users

Admin panel user accounts.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | BINARY(16) | Yes | UUID primary key | Session reference | UUID v4 format |
| email | VARCHAR(255) | Yes | Login identifier | Authentication; unique | Valid email format; UNIQUE |
| password_hash | VARCHAR(255) | Yes | Bcrypt hash | Authentication | Bcrypt with cost >= 12 |
| display_name | VARCHAR(100) | No | Human-readable name | UI display | Max 100 chars |
| locale | VARCHAR(10) | Yes | Preferred UI language | Admin panel language | ISO 639-1; default: 'de' |
| is_active | BOOLEAN | Yes | Account status | Inactive users cannot login | Default: true |
| last_login_at | DATETIME | No | Last successful login | Security monitoring | Auto-updated on login |
| created_at | DATETIME | Yes | Record creation | Audit trail | Auto-set |
| updated_at | DATETIME | Yes | Last modification | Audit trail | Auto-updated |

**Indexes**: `email` (UNIQUE), `is_active`

**Access**: All admin users have full access (CRUD all entities, settlements, GDPR workflows, user management, audit log).

---

### terminals

Registered terminal devices.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | BINARY(16) | Yes | UUID primary key | Terminal identification | UUID v4 format |
| name | VARCHAR(100) | Yes | Human-readable name | Display in admin panel | Max 100 chars; e.g., "Bar-Terminal-1" |
| terminal_id | VARCHAR(50) | Yes | Configured terminal identifier | Sent in X-Terminal-Id header | 1-50 chars; UNIQUE |
| token_hash | VARCHAR(255) | Yes | Bcrypt hash of API token | Authentication validation | Bcrypt with cost >= 12 |
| is_active | BOOLEAN | Yes | Authorization status | Inactive terminals get 401 | Default: true |
| last_sync_at | DATETIME | No | Last successful sync | Monitoring; detect offline terminals | Updated on each sync |
| last_sync_ip | VARCHAR(45) | No | IP of last sync | Security monitoring | IPv4 or IPv6 |
| created_at | DATETIME | Yes | Record creation | Audit trail | Auto-set |
| updated_at | DATETIME | Yes | Last modification | Audit trail | Auto-updated |

**Indexes**: `terminal_id` (UNIQUE), `is_active`

**Token**: 64-character hex string; stored as bcrypt hash; shown once during creation.

---

### unknown_card_scans

Cards scanned at terminal but not assigned to any member.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | INT | Yes | Auto-increment PK | Internal reference | Auto-generated |
| card_uid | VARCHAR(20) | Yes | Scanned card identifier | For assignment to new member | 8-20 hex chars, uppercase; UNIQUE |
| terminal_id | BINARY(16) | No | FK to terminals | Which terminal first scanned | Valid terminal reference |
| first_seen_at | DATETIME | Yes | First scan timestamp | When card was first detected | Auto-set |
| last_seen_at | DATETIME | Yes | Last scan timestamp | Recency indicator | Updated on each scan |
| scan_count | INT | Yes | Number of scan attempts | Frequency indicator | Default: 1; incremented |

**Indexes**: `card_uid` (UNIQUE), `last_seen_at`

**Cleanup**: Entries removed when card is assigned to a member.

---

### sepa_config

Organization-level SEPA configuration (single row).

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | INT | Yes | Fixed value = 1 | Single-row constraint | CHECK (id = 1) |
| creditor_id | VARCHAR(35) | Yes | Gläubiger-ID | SEPA XML header; immutable after first set | German format: DE + 2 digits + ZZZ + identifier |
| creditor_name | VARCHAR(70) | Yes | Organization name | SEPA XML; must match bank records | Max 70 chars (SEPA limit) |
| creditor_iban | VARCHAR(34) | Yes | Organization bank account | Receiving account for collections | ISO 13616 IBAN + mod-97 checksum |
| creditor_address_street | VARCHAR(70) | Yes | Street address | SEPA XML | Max 70 chars |
| creditor_address_city | VARCHAR(70) | Yes | City and postal code | SEPA XML | Max 70 chars |
| creditor_address_country | VARCHAR(2) | Yes | ISO country code | SEPA XML | ISO 3166-1 alpha-2; default: 'DE' |
| updated_by_admin_id | BINARY(16) | No | FK to admin_users | Audit: who last modified | Valid admin reference |
| created_at | DATETIME | Yes | Record creation | Audit trail | Auto-set |
| updated_at | DATETIME | Yes | Last modification | Audit trail | Auto-updated |

**Constraint**: Only one row allowed (id = 1).

---

### audit_log

Append-only audit trail for all master data changes.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | BIGINT | Yes | Auto-increment PK | Unique log entry | Auto-generated |
| admin_user_id | BINARY(16) | No | FK to admin_users | Who performed action; NULL for system/failed login | Valid admin or NULL |
| action | ENUM | Yes | Type of action | Categorization | 'create', 'update', 'delete', 'anonymize', 'login', 'logout', 'login_failed', 'export', 'settlement_create', 'settlement_cancel', 'settlement_export' |
| entity_type | VARCHAR(50) | Yes | Affected table/entity | Filtering | 'member', 'product', 'category', 'admin_user', 'terminal', 'settlement', 'sepa_config' |
| entity_id | VARCHAR(36) | No | PK of affected record | Linking to entity | UUID string or NULL |
| old_values | JSON | No | Values before change | Audit diff | NULL for create/login |
| new_values | JSON | No | Values after change | Audit diff | NULL for delete/logout |
| ip_address | VARCHAR(45) | No | Client IP | Security monitoring | IPv4 or IPv6 |
| user_agent | VARCHAR(500) | No | Browser/client | Security monitoring | Max 500 chars |
| created_at | DATETIME | Yes | Action timestamp | Audit timeline | Auto-set |

**Indexes**: `admin_user_id`, `action`, `entity_type`, `entity_id`, `created_at`

**Sensitive Data**: IBAN masked as `DE89****...****4567`; passwords logged as `[CHANGED]`; tokens never logged.

**Retention**: 10 years per § 147 AO (German tax code).

---

## Frontend Entities (Terminal SQLite)

The terminal maintains a local SQLite database for offline operation.

### members_cache

Read-only cache of members. Minimal fields for terminal operation.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | TEXT | Yes | UUID from backend | Transaction linking | UUID v4 string |
| card_uid | TEXT | No | RFID/NFC identifier | Card scan lookup | 8-20 hex chars, uppercase; indexed |
| first_name | TEXT | No | Given name | Display greeting | — |
| last_name | TEXT | No | Family name | Display greeting | — |
| preferred_language | TEXT | Yes | ISO 639-1 code | Product name localization | — |
| is_active | INTEGER | Yes | Account status | Reject inactive members | 0 or 1 |
| is_sepa_valid | INTEGER | Yes | SEPA mandate status | Reject members without valid SEPA data | 0 or 1; derived from backend iban + mandate_reference |
| updated_at | TEXT | Yes | ISO 8601 timestamp | Delta sync comparison | — |

**Indexes**: `card_uid`, `updated_at`

**Not Cached**: email, iban, mandate_reference, mandate_signed_at (sensitive; backend-only).

**SEPA Validation**: `is_sepa_valid` is calculated by backend during sync as `(iban IS NOT NULL AND mandate_reference IS NOT NULL)`. Terminal blocks access if `is_sepa_valid = 0`. See [ADR-0020](../adr/0020-sepa-mandate-requirement-terminal-access.md).

---

### products_cache

Read-only cache of products.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | TEXT | Yes | UUID from backend | Transaction linking | UUID v4 string |
| category_id | TEXT | Yes | FK to categories_cache | Category grouping | — |
| names | TEXT | Yes | JSON string | Multilingual display | Parse at display time |
| descriptions | TEXT | No | JSON string | Optional details | Parse at display time |
| price_cents | INTEGER | Yes | Price in cents | Transaction creation | — |
| is_active | INTEGER | Yes | Availability | Hide inactive | 0 or 1 |
| updated_at | TEXT | Yes | ISO 8601 timestamp | Delta sync comparison | — |

**Indexes**: `category_id`, `is_active`, `updated_at`

---

### categories_cache

Read-only cache of categories.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | TEXT | Yes | UUID from backend | Product grouping | UUID v4 string |
| names | TEXT | Yes | JSON string | Multilingual tab labels | Parse at display time |
| display_order | INTEGER | Yes | Sort position | Tab ordering | — |
| is_active | INTEGER | Yes | Availability | Hide inactive categories and their products | 0 or 1 |
| updated_at | TEXT | Yes | ISO 8601 timestamp | Delta sync comparison | — |

**Indexes**: `display_order`, `is_active`, `updated_at`

**Terminal Display**: Only show categories where `is_active = 1`. Products in inactive categories are hidden regardless of product's own `is_active` status.

---

### transactions_local

Local queue of transactions pending sync to backend.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| id | TEXT | Yes | Client-generated UUID | Idempotency key | UUID v4 string |
| member_id | TEXT | Yes | FK to members_cache | Who made purchase | — |
| product_id | TEXT | No | FK to products_cache | What was purchased | — |
| amount_cents | INTEGER | Yes | Amount in cents | Balance change | Non-zero |
| transaction_type | TEXT | Yes | Type | Categorization | 'purchase', 'correction' |
| notes | TEXT | No | Description | Optional | — |
| created_at | TEXT | Yes | ISO 8601 timestamp | Transaction time | — |
| synced | INTEGER | Yes | Sync status | Upload tracking | 0 = pending; 1 = synced |

**Indexes**: `member_id`, `synced`, `created_at`

---

### sync_state

Sync metadata for delta synchronization.

| Field | Type | Required | Description | Business Impact | Validation |
|-------|------|----------|-------------|-----------------|------------|
| key | TEXT | Yes | State key | Unique identifier | PK |
| value | TEXT | Yes | State value | Timestamp or status | — |

**Common Keys**:
- `members_last_sync`: ISO 8601 timestamp of last members sync
- `products_last_sync`: ISO 8601 timestamp of last products sync
- `categories_last_sync`: ISO 8601 timestamp of last categories sync
- `last_sync_attempt`: ISO 8601 timestamp of last sync attempt
- `last_sync_success`: ISO 8601 timestamp of last successful sync

---

## Entity Relationships

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  categories │◄────│  products   │     │   members   │
└─────────────┘     └──────┬──────┘     └──────┬──────┘
                           │                    │
                           │                    │
                    ┌──────▼──────┐      ┌──────▼──────┐
                    │transactions │◄─────│settlement_  │
                    │             │      │items        │
                    └──────┬──────┘      └──────┬──────┘
                           │                    │
                           │              ┌─────▼──────┐
                    ┌──────▼──────┐       │settlements │
                    │  terminals  │       └────────────┘
                    └─────────────┘

┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│ admin_users │────►│  audit_log  │     │ sepa_config │
└─────────────┘     └─────────────┘     └─────────────┘

┌─────────────────┐
│unknown_card_scans│
└─────────────────┘
```

---

## Data Type Conventions

| Concept | Backend (MariaDB) | Frontend (SQLite) | API (JSON) |
|---------|-------------------|-------------------|------------|
| UUID | BINARY(16) | TEXT | String (hyphenated) |
| Money | INT (cents) | INTEGER (cents) | Number (cents) |
| Boolean | BOOLEAN | INTEGER (0/1) | Boolean |
| Timestamp | DATETIME | TEXT (ISO 8601) | String (ISO 8601) |
| JSON | JSON | TEXT | Object |
| Enum | ENUM | TEXT | String |

---

## Sync Behavior

| Entity | Direction | Method |
|--------|-----------|--------|
| members | Backend → Terminal | Delta sync via `?since=` timestamp |
| products | Backend → Terminal | Delta sync via `?since=` timestamp |
| categories | Backend → Terminal | Delta sync via `?since=` timestamp |
| transactions | Terminal → Backend | Batch upload; idempotent via UUID |

**Delta Sync**: Terminal sends last sync timestamp; backend returns records where `updated_at >= since`. The bound is inclusive because the column has second precision: a strict `>` would lose every row written later in the cursor's own second, permanently ([#84](https://github.com/dgloeckner/clubbar/issues/84)). The cursor only advances past a second once that second is over, so the boundary is re-sent at most until then — see [ADR-0012](../adr/0012-eventual-consistency-frontend-caching.md) §Query Operator Choice.

**Idempotent Upload**: Backend inserts by client-generated UUID and catches the duplicate-key error alone, so a replayed transaction is accepted without being booked twice. `INSERT IGNORE` is deliberately *not* used: it also swallows the errors that mean a row was refused, which made a lost sale indistinguishable from a replay ([#82](https://github.com/dgloeckner/ruderbar/issues/82)).

---

## References

- [ADR-0001: Monetary Values as Integer Cents](../adr/0001-monetary-values-as-integer-cents.md)
- [ADR-0002: Product Internationalization](../adr/0002-product-internationalization.md)
- [ADR-0004: Immutable Transaction Storage](../adr/0004-immutable-transaction-storage.md)
- [ADR-0005: IBAN Storage and Validation](../adr/0005-iban-storage-and-validation.md)
- [ADR-0006: SEPA Mandate Reference Strategy](../adr/0006-sepa-mandate-reference-strategy.md)
- [ADR-0007: Organization SEPA Configuration](../adr/0007-organization-sepa-configuration-storage.md)
- [ADR-0013: Audit Logging](../adr/0013-audit-logging.md)
- [ADR-0015: Authentication and Authorization](../adr/0015-authentication-and-authorization-strategy.md)
- [ADR-0020: SEPA Mandate Requirement for Terminal Access](../adr/0020-sepa-mandate-requirement-terminal-access.md)
