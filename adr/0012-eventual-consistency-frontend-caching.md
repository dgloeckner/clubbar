# ADR-0012: Eventual Consistency and Frontend Caching Strategy

**Status**: Accepted

**Date**: 2025-01-23

---

## Context

The Member Bar system operates across multiple components: Electron-based terminals (potentially multiple), a PHP backend, and a MariaDB database. Terminals must function in environments with unreliable or intermittent network connectivity (community centers, clubs, remote locations).

Key constraints and requirements:

1. **Offline operation**: Terminals must process transactions without network connectivity
2. **Multi-terminal deployment**: Multiple terminals may operate simultaneously
3. **Bandwidth efficiency**: Sync should minimize data transfer (limited/metered connections)
4. **Conflict avoidance**: System must handle concurrent operations without complex merge logic
5. **Data integrity**: Financial transactions must never be lost or duplicated
6. **Audit compliance**: Complete transaction history required for accounting/tax purposes
7. **Shared hosting**: Backend runs on standard PHP hosting without WebSockets or background workers

---

## Decision

**The system uses an eventually consistent architecture where terminals maintain a local SQLite cache of master data (members, products) and queue transactions locally. Periodic delta synchronization reconciles state with the backend. Conflicts are avoided through single-writer patterns and append-only transaction storage.**

### Core Principles

1. **Eventually consistent**: Frontend operates on local data; short-term inconsistencies are accepted
2. **Offline-first**: Terminal is fully functional without network; syncs when connectivity is available
3. **Single writer per entity type**: Members/products are read-only on terminals (backend is authoritative)
4. **Append-only transactions**: No updates or deletes; corrections via reverse transactions
5. **Idempotent sync**: Client-generated UUIDs enable safe retry without duplication
6. **Data minimization**: Terminals cache only operationally necessary fields

### Data Flow Directions

```mermaid
flowchart LR
    subgraph Terminal["Terminal (SQLite)"]
        UC[users_cache<br/>read-only]
        PC[products_cache<br/>read-only]
        TL[transactions_local<br/>write]
    end

    subgraph Backend["Backend (MariaDB)"]
        UM[members<br/>master]
        PM[products<br/>master]
        TM[transactions<br/>master]
    end

    UM -->|"GET /sync/members?since=ts<br/>Delta sync"| UC
    PM -->|"GET /sync/products?since=ts<br/>Delta sync"| PC
    TL -->|"POST /sync/transactions<br/>Batch upload, idempotent"| TM
```

### Cached Data (Terminal)

| Entity | Direction | Cache Type | Fields Stored |
|--------|-----------|------------|---------------|
| Members | Backend → Terminal | Read-only | id, card_uid, first_name, last_name, preferred_language, is_active, deleted_at |
| Products | Backend → Terminal | Read-only | id, names (JSON), prices_cents, category, is_active |
| Transactions | Terminal → Backend | Write queue | id, member_id, product_id, amount_cents, created_at, synced (flag) |

**Not cached on terminal** (sensitive data remains backend-only):
- IBAN, BIC, mandate_reference
- Full contact details
- Audit logs

### Synchronization Cycle

```mermaid
sequenceDiagram
    participant T as Terminal
    participant B as Backend
    participant DB as MariaDB

    Note over T: Sync cycle starts (every 60s)

    T->>T: 1. Connectivity check
    alt No connection
        T->>T: Skip sync, retry later
    else Connected
        T->>B: 2. GET /sync/members?since={last_sync_ts}
        B->>DB: SELECT * WHERE updated_at > since
        DB-->>B: Changed members
        B-->>T: Delta response
        T->>T: UPSERT into members_cache
        T->>T: Remove soft-deleted members

        T->>B: 3. GET /sync/products?since={last_sync_ts}
        B->>DB: SELECT * WHERE updated_at > since
        DB-->>B: Changed products
        B-->>T: Delta response
        T->>T: UPSERT into products_cache

        T->>T: 4. SELECT * FROM transactions WHERE synced = false
        T->>B: POST /sync/transactions (batch, max 100)
        B->>DB: INSERT IGNORE (deduplicate by UUID)
        DB-->>B: Accepted UUIDs
        B-->>T: Confirmation
        T->>T: UPDATE transactions SET synced = true

        T->>T: 5. Persist new sync timestamp
    end
```

### Conflict Avoidance Strategy

| Entity | Strategy | Rationale |
|--------|----------|-----------|
| Members | Single writer (backend only) | Terminal is read-only; no conflicts possible |
| Products | Single writer (backend only) | Terminal is read-only; no conflicts possible |
| Transactions | Append-only + UUID deduplication | No updates/deletes; idempotent inserts |

### Robustness and Error Handling

| Scenario | Behavior |
|----------|----------|
| Network unreachable | Local operation continues; transactions queued |
| Sync interrupted mid-cycle | Retry in next cycle; partial state acceptable |
| Duplicate transaction sent | Backend deduplicates via UUID (INSERT IGNORE) |
| Response lost after upload | Terminal resends; backend ignores duplicate |
| Terminal restart | Sync state persisted in SQLite; resumes cleanly |
| Member updated while offline | Terminal shows stale data until next sync |
| Member deleted while offline | RFID scan returns "Unknown member" after sync |

### Sync Intervals (Configurable)

| Data Type | Recommended Interval | Rationale |
|-----------|---------------------|-----------|
| Members/Products | 60 seconds | Master data changes infrequently |
| Transactions | 30 seconds | Financial data should sync promptly |
| Connectivity check | Before each sync | Avoid unnecessary timeout waits |

### Offline Capabilities

**Fully functional offline:**
- RFID card scanning and member lookup
- Product selection and display
- Transaction recording
- Balance display (local transactions only)

**Requires connectivity:**
- New member recognition (not yet synced)
- Price/product updates (until sync)
- Member status changes (blocked, deleted)
- Accurate cumulative balance (backend aggregation)

---

## Consequences

### Positive

- **High availability**: Terminal operates indefinitely without network
- **Simple conflict resolution**: No merge conflicts due to single-writer + append-only patterns
- **Safe retries**: Idempotent sync operations prevent data corruption
- **Bandwidth efficient**: Delta sync transfers only changed records
- **Audit-friendly**: Append-only transactions provide complete history
- **Hosting compatible**: Works on shared hosting without WebSockets or workers

### Negative

- **Temporary inconsistency**: Terminal may show stale member/product data (up to sync interval)
- **Delayed visibility**: Transactions not visible in admin panel until synced
- **Balance discrepancy**: Member's displayed balance excludes unsynced transactions from other terminals
- **No real-time updates**: Price changes require sync cycle to propagate

### Mitigations

1. **Stale data**: Display "Last synced: X minutes ago" indicator; warn after extended offline periods
2. **Balance accuracy**: Show "Local balance" disclaimer; full balance requires backend query
3. **Offline warning**: Alert after 1 hour offline; prominent warning after 24 hours

---

## Alternatives Considered

### Alternative 1: Real-Time Sync (WebSockets)

Maintain persistent WebSocket connection for instant updates.

**Pros**: Immediate consistency; real-time balance updates
**Cons**:
- Requires WebSocket-capable hosting (not shared hosting compatible)
- Complex reconnection logic
- Higher bandwidth usage
- Single point of failure if connection drops

**Rejected**: Incompatible with shared hosting constraint; adds complexity without proportional benefit for low-frequency updates.

### Alternative 2: Full Sync (No Delta)

Download complete member/product lists on each sync cycle.

**Pros**: Simpler implementation; guaranteed consistency
**Cons**:
- Bandwidth inefficient (transfers unchanged data)
- Slower sync cycles (larger payloads)
- Poor performance with large member bases

**Rejected**: Delta sync provides 90%+ bandwidth reduction for typical usage patterns.

### Alternative 3: Optimistic Locking with Conflict Resolution

Allow terminals to update members/products; resolve conflicts on sync.

**Pros**: More flexible; terminals can correct data
**Cons**:
- Complex merge logic required
- Conflict resolution UI needed
- Risk of data loss or corruption
- Audit trail complexity

**Rejected**: Single-writer pattern eliminates conflict scenarios entirely; admin panel handles all master data changes.

### Alternative 4: Synchronous API Calls (No Local Cache)

Query backend for every operation; no local storage.

**Pros**: Always consistent; no cache invalidation concerns
**Cons**:
- Non-functional offline
- High latency for every operation
- Network dependency for basic functions
- Poor user experience

**Rejected**: Violates offline-first requirement; unacceptable for unreliable network environments.

---

## Related Decisions

- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - Append-only pattern enables conflict-free sync
- [ADR-0003: GZIP Compression for HTTP](./0003-gzip-compression-http.md) - Reduces sync payload sizes by ~85%

---

## References

- **Offline-First Design**: [Offline First Community](https://offlinefirst.org/)
- **Eventual Consistency**: [CAP Theorem](https://en.wikipedia.org/wiki/CAP_theorem)
- **Idempotency Patterns**: [Idempotent REST APIs](https://restfulapi.net/idempotent-rest-apis/)
- **SQLite in Electron**: [better-sqlite3](https://github.com/WiseLibs/better-sqlite3)

---

## Post-Implementation Monitoring

- Track sync failure rates and retry patterns
- Monitor average sync cycle duration
- Measure offline operation duration distribution
- Alert on terminals offline > 24 hours
- Track transaction upload latency (creation to sync)
- Monitor cache hit rates for member/product lookups
