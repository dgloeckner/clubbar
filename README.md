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
| Spreadsheet accounting chaos | **Automated SEPA settlement** — Generate bank-ready XML |
| GDPR compliance concerns | **Privacy by design** — Anonymization workflows built-in |
| Expensive POS hardware | **Commodity hardware** — Runs on any tablet or Raspberry Pi with a USB RFID reader |
| Recurring SaaS fees | **Self-hosted & free** — No subscriptions, no vendor lock-in, your data stays yours |
| Complex software requirements | **Simple deployment** — PHP backend, SQLite/MySQL |

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

    Terminal <-->|"Sync API"| Backend
    Admin <-->|"REST API"| Backend
```

---

## Key Features

### For Members
- **Tap & Go** — RFID/NFC card identification
- **Personal tab** — View outstanding balance anytime
- **Multilingual** — UI in member's preferred language

### For Administrators
- **Member management** — CRUD, RFID assignment, GDPR export/anonymization
- **Product catalog** — Categories, multilingual names, prices in cents
- **Settlement workflow** — Preview, finalize, export SEPA XML or CSV
- **Audit trail** — Complete history of all administrative actions

### Technical Highlights
- **Offline-first architecture** — Terminal caches data locally, syncs when connected
- **Immutable transactions** — Append-only ledger, corrections via reverse entries
- **Idempotent sync** — Client-generated UUIDs prevent duplicates
- **SEPA Direct Debit** — pain.008.001.02 XML generation with mandate handling

---

## Documentation

| Document | Description |
|----------|-------------|
| [CLAUDE.md](./CLAUDE.md) | Developer guide and project conventions |
| [ADRs](./adr/) | Architecture Decision Records (22 decisions) |
| [Use Cases](./use-cases/README.md) | Functional requirements by domain (64 use cases with status) |
| [API Specs](./api/) | OpenAPI 3.0 specifications |
| [Data Model](./docs/) | Entity-Relationship diagrams |
| [Deployment Guide](./docs/deployment.md) | Production deployment, backups, and security |
| [Terminal Install](./terminal-frontend/INSTALL.md) | Terminal app deployment on Raspberry Pi |

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
