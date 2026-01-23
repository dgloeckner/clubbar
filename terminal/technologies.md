# Terminal – Frontend Technology Stack and Architecture

This is the terminal frontend where users buy products; used on a touch screen.

## Technologies

| Layer | Technology | Responsibility |
|-------|------------|----------------|
| **Runtime** | Electron | Desktop app, hardware access (RFID), filesystem |
| **UI Framework** | React | Components, state, rendering |
| **Language** | TypeScript | End-to-end type safety |
| **Styling** | CSS-in-JS (inline styles) | Custom design system, touch-optimized dark theme |
| **Database** | SQLite + better-sqlite3 | Local persistence, offline-capable |
| **ORM** | Drizzle ORM | Schema, types, queries |
| **IPC** | Electron contextBridge | Secure communication Main ↔ Renderer |
| **Build** | Vite + electron-builder | Fast dev, packaging |

---

## Architecture Layers

| Layer | Responsibility |
|-------|----------------|
| **Schema** | Single source of truth for tables + types |
| **Repository** | Encapsulated DB queries |
| **IPC Handler** | API endpoints for Renderer |
| **Preload** | Expose typed API |
| **Hooks** | React state + data fetching |
| **Components** | UI |

---

## Architecture Diagram

```mermaid
flowchart LR
    subgraph Renderer["Renderer Process"]
        RC[React Components]
        H[Hooks]
        API[window.api]
        RC --> H --> API
    end

    subgraph Main["Main Process"]
        IPC[IPC Handler]
        Repo[Repositories]
        ORM[Drizzle ORM]
        DB[(SQLite)]
        SW[Sync Worker]
        IPC --> Repo --> ORM --> DB
        SW --> Repo
    end

    subgraph HW["Hardware"]
        RFID[RFID Reader]
    end

    subgraph Backend["Backend (PHP + MariaDB)"]
        REST[REST API]
        MDB[(MariaDB)]
        REST --> MDB
    end

    API <-->|IPC| IPC
    Main --> HW
    SW <-->|"HTTP/HTTPS"| REST
```

---

## Synchronization Flow

```mermaid
sequenceDiagram
    participant T as Terminal (SQLite)
    participant B as Backend (MariaDB)

    Note over T,B: Sync Cycle (every 60s)

    T->>T: Check connectivity

    rect rgb(230, 245, 230)
        Note over T,B: Download (Read-Only Caches)
        T->>B: GET /sync/members?since={last_sync}
        B-->>T: Members delta (JSON)
        T->>T: UPSERT into members_cache

        T->>B: GET /sync/products?since={last_sync}
        B-->>T: Products delta (JSON)
        T->>T: UPSERT into products_cache
    end

    rect rgb(245, 230, 230)
        Note over T,B: Upload (Write Queue)
        T->>T: SELECT * FROM transactions_local WHERE synced=0
        T->>B: POST /sync/transactions (batch, max 100)
        B-->>T: Success (UUIDs acknowledged)
        T->>T: UPDATE transactions_local SET synced=1
    end

    T->>T: Persist sync timestamps
```
