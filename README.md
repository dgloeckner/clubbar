<p align="center">
  <img src="artwork/clubbar-logo.svg" alt="Club Bar Logo" width="128" height="128">
</p>

<h1 align="center">Club Bar</h1>

<p align="center">
  <strong>Open-source POS system for member-managed bars and clubs</strong>
</p>

Club Bar is a complete point-of-sale solution designed for sports clubs, community centers, and member organizations that operate their own bar or canteen. Built with privacy, offline capability, and SEPA settlement in mind.

---

## Why Club Bar?

| Challenge | Solution |
|-----------|----------|
| Unreliable network at venue | **Offline-first** — Terminal works without internet |
| Manual billing is error-prone | **RFID identification** — Tap card, select products, done |
| One admin account, one blast radius | **Role-based access** — Treasurer, bar steward, and full admin see only what their office needs |
| Spreadsheet accounting chaos | **Automated SEPA settlement** — Generate bank-ready XML |
| GDPR compliance concerns | **Privacy by design** — Anonymization workflows built-in |
| Expensive POS hardware | **Commodity hardware** — Runs on any tablet or Raspberry Pi with a USB RFID reader |
| Recurring SaaS fees | **Self-hosted & free** — No subscriptions, no vendor lock-in, your data stays yours |
| Complex software requirements | **Simple deployment** — PHP backend, MariaDB |

---

## System Components

```mermaid
flowchart TB
    subgraph Terminal["Terminal App (Flutter)"]
        T1[RFID card scan]
        T2[Product selection]
        T3[Offline checkout]
        T4[Touch-optimized UI]
    end

    subgraph Admin["Admin Panel (React SPA)"]
        A1[Member management]
        A2[Product catalog]
        A3[SEPA settlements]
        A4[GDPR compliance]
    end

    subgraph Backend["Backend API (PHP)"]
        B1[REST API]
        B2[SEPA XML export]
        B3[Audit logging]
        B4[Delta sync]
        DB[(Database)]
        B1 --> DB
    end

    subgraph Dispenser["Token Dispenser (optional)"]
        D1[ESP8266 WiFi controller]
        D2[Azkoyen Hopper]
        D1 --> D2
    end

    Terminal <-->|"Sync API"| Backend
    Admin <-->|"REST API"| Backend
    Terminal <-->|"HTTP REST\n(local WiFi)"| Dispenser
```

---

## Demo

### Terminal App

<video src="https://github.com/user-attachments/assets/750e723f-fc46-4e20-a06f-1b9375203ac2" width="100%" autoplay muted loop playsinline></video>

> Terminal walkthrough: RFID card scan, multilingual product browsing (German → English), category selection (Drinks, Snacks, Sauna), shopping cart, and checkout.

### Admin Panel

<video src="https://github.com/user-attachments/assets/d3c429ca-b116-44dc-8490-2c0cb2331de8" width="100%" autoplay muted loop playsinline></video>

> Admin Panel walkthrough: dashboard, member & product management, journal, settlements, SEPA export, reports, settings, and audit log.

---

## Key Features

### For Members
- **Tap & Go** — RFID/NFC card identification
- **Personal tab** — View outstanding balance anytime
- **Multilingual** — UI in member's preferred language

### For Administrators
- **Member management** — CRUD, RFID assignment, GDPR export/anonymization
- **Role-based access** — `admin`, `Kassenwart` (treasurer), and `Getränkewart` (bar steward) accounts each see only the office they hold. See [Role-Based Admin Access](./docs/role-based-access.md)
- **Product catalog** — Categories, multilingual names, prices in cents
- **Settlement workflow** — Preview, finalize, export SEPA XML or CSV
- **Audit trail** — Complete history of all administrative actions

### Technical Highlights
- **Offline-first architecture** — Terminal caches data locally, syncs when connected
- **Immutable transactions** — Append-only ledger, corrections via reverse entries
- **Idempotent sync** — Client-generated UUIDs prevent duplicates
- **SEPA Direct Debit** — pain.008.001.08 XML generation with mandate handling
- **Tiered admin roles** — Default-deny, fail-closed authorization enforced on every backend route ([ADR-0044](./adr/0044-tiered-admin-roles.md))

### Optional: Token Dispenser Integration

Club Bar supports an optional hardware integration with a physical token dispenser for venues that use coin-operated equipment (saunas, laundromats, arcades). The terminal can trigger token dispensing as part of the checkout flow — members tap their RFID card, select a token product, and tokens are dispensed automatically.

**How it works:**
- An **[ESP8266 microcontroller](https://github.com/dgloeckner/remote-token-dispenser)** (Wemos D1 Mini) drives an **Azkoyen Hopper U-II** industrial token dispenser over GPIO with optocoupler isolation
- The terminal communicates with the ESP8266 via **HTTP REST over local WiFi** — no cloud dependency
- **Dispense-first, pay-after** model — tokens are physically dispensed before the transaction is recorded, eliminating complex refund scenarios
- **Crash-resilient** — the ESP8266 persists state to flash memory; survives power loss mid-transaction with exact token counts
- **Idempotent** — client-controlled transaction IDs prevent double-dispensing on retries
- **Jam detection** — watchdog timer monitors token pulses and halts on mechanical issues

Products that require dispensing are flagged with `requires_dispenser` in the product catalog. The dispenser is configured entirely on the terminal side via `config.json` — no backend changes needed. See the [Terminal Installation Guide](./terminal-frontend/INSTALL.md#7-optional-token-dispenser) for deployment details and the [remote-token-dispenser](https://github.com/dgloeckner/remote-token-dispenser) repository for firmware, hardware schematics, and a Go-based mock for development.

---

## Documentation

| Document | Description |
|----------|-------------|
| [CLAUDE.md](./CLAUDE.md) | Developer guide and project conventions |
| [ADRs](./adr/) | Architecture Decision Records (22 decisions) |
| [Use Cases](./use-cases/README.md) | Functional requirements by domain (64 use cases with status) |
| [API Specs](./api/) | OpenAPI 3.0 specifications |
| [Data Model](./docs/) | Entity-Relationship diagrams |
| [Role-Based Admin Access](./docs/role-based-access.md) | `admin` / `Kassenwart` / `Getränkewart` roles, authorization flow, diagrams |
| [Security Concept](./docs/security-concept.md) | Defense-in-depth overview — transport, auth, authorization, encryption at rest, monitoring |
| [Deployment Guide](./docs/deployment.md) | Production deployment, backups, and security |
| [Terminal Install](./terminal-frontend/INSTALL.md) | Terminal app deployment on Raspberry Pi |
| [Token Dispenser](https://github.com/dgloeckner/remote-token-dispenser) | Optional hardware integration — ESP8266 firmware, schematics, mock server |

---

## Deployment

For production deployment instructions, see the **[Deployment Guide](./docs/deployment.md)**. It covers:

- **Self-hosted package** -- upload ZIP to shared hosting, run the graphical web installer
- **Security hardening** -- HTTPS, database access
- **Database backups** -- automated daily backups with 30-day retention
- **Monitoring** -- health endpoint polling and application logs
- **Upgrading and rollback** -- step-by-step procedures

For terminal app deployment on Raspberry Pi or Linux, see the **[Terminal Installation Guide](./terminal-frontend/INSTALL.md)**.

---

## Security

Club Bar layers transport security, strong authentication, role-based
authorization, encryption at rest, and audit logging / anomaly detection —
no single layer is trusted to carry the whole system. See the
**[Security Concept](./docs/security-concept.md)** for the full picture,
with diagrams for each layer.

| Layer | Mechanism |
|---|---|
| Transport | HTTPS required in production, HSTS, CSP |
| Authentication | Mandatory TOTP 2FA for admins; 256-bit bearer tokens for terminals |
| Authorization | Role-based access (`admin` / `Kassenwart` / `Getränkewart`), fail-closed on every request |
| Data at rest | IBANs encrypted with a libsodium sealed box — the server holds only the public key, never a decryptable copy |
| Monitoring | Append-only audit log, terminal credential anomaly detection, an announced (never silent) token-issuance flow |

The rest of this section covers day-to-day operator guidance; see the
[Security Concept](./docs/security-concept.md) for how these mechanisms fit
together and why each was chosen.

### Admin Panel

- **Role-based access** — Admin accounts hold `admin`, `Kassenwart`, and/or `Getränkewart`; each office reaches only the routes and panel sections it needs, enforced with a default-deny, fail-closed backend check on every request. See [Role-Based Admin Access](./docs/role-based-access.md) and [ADR-0044](./adr/0044-tiered-admin-roles.md).
- **Mandatory two-factor authentication (TOTP)** — Every admin account must enroll a TOTP authenticator app on first login. 2FA cannot be bypassed.
- **Back up your TOTP secret** — During enrollment, a **backup key** is shown below the QR code, with a button to copy it. Store it in a password manager before finishing enrollment. There are no recovery codes.
- **Keep two admin accounts** — Recovery is admin-to-admin: any signed-in admin can reset another's password or 2FA from Settings → Admin Users. A single-admin installation that loses its authenticator has no recovery path inside the application and needs direct database access. See the **[Admin Lockout Runbook](./docs/runbook-admin-lockout.md)**.
- **Changing your own password or email needs a second factor** — Both re-ask for your password and a current 2FA code, and both sign out every *other* session on the account. The address an email was changed away from is notified.
- **Use HTTPS in production** — Admin credentials and session cookies must be transmitted over TLS. See the [Deployment Guide](./docs/deployment.md) for certificate setup.
- **Sessions expire** — Admin sessions time out after 2 hours of inactivity.

### Terminal

- **Bearer token authentication** — Each terminal authenticates with a unique, revocable API token. Tokens can be rotated from the Admin Panel without affecting other terminals.
- **Network isolation** — The terminal and the optional token dispenser communicate over local WiFi only; no internet access is required after initial setup.
- **Physical security** — RFID cards are member identifiers. Keep the terminal in a supervised area — an unattended, unlocked terminal allows transactions on any tapped card.

### Recommended Authenticator Apps

| App | Platform | Notes |
|-----|----------|-------|
| [Aegis](https://getaegis.app/) | Android | Open source, encrypted local backups |
| [Raivo OTP](https://raivo-otp.com/) | iOS | Open source, iCloud backup |
| [Google Authenticator](https://support.google.com/accounts/answer/1066447) | Android & iOS | Simple, widely used |
| [Authy](https://authy.com/) | Android & iOS | Multi-device sync |

---

## Development Setup

For local development using Docker Compose, see **[DEV_SETUP.md](./DEV_SETUP.md)**.

---

## Technology Stack

| Component | Technologies |
|-----------|--------------|
| **Terminal** | Flutter, Dart, Drift (SQLite), Provider |
| **Admin Panel** | React, TypeScript, Vite, Emotion, Recharts |
| **Backend** | PHP 8.3, MariaDB, PDO |
| **Testing** | PHPUnit, Vitest, Playwright, Flutter integration_test |

---

## Contributing

We welcome contributions! Please read:

1. [CLAUDE.md](./CLAUDE.md) — Project conventions and guidelines
2. [ADRs](./adr/) — Understand architectural decisions before proposing changes
3. [Use Cases](./use-cases/) — Reference when implementing features

### Development Approach

- **TDD** — Write tests before implementation ([ADR-0022](./adr/0022-test-strategy-and-automation.md))
- **Milestones** — Planned approach over tackling everything at once
- **ADRs are binding** — Don't change without discussion

---

## License

Apache-2.0. See [LICENSE](./LICENSE) for details.

---

## Acknowledgments

Built for sports clubs and member organizations that need a simple, privacy-respecting way to manage their bar.

*"Club Bar"* — Your members. Your bar. Your system.
