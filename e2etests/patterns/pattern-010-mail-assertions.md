# Pattern 010: Asserting on Delivered Mail

**Status**: Established (#409)
**Applies to**: any test whose subject is a message the club sends
**Reference implementation**: `tests/mail/prenotification-chain.spec.ts`, `utils/mailpit.ts`, `utils/drain.ts`

---

## Problem

A queue row that says `sent` is *our bookkeeping*. What a member receives is a
message — and the difference is where the interesting failures live: a template
that renders a blank amount, a text part that says "see the HTML version", a
duplicate that arrives because idempotency broke somewhere between the unique
index and the claim.

Testing the sender in isolation cannot see any of that, because the sender is
the thing under test. The assertion has to be made against a real SMTP server
that a real drain really talked to.

## Solution

Three moving parts, and each solves a different half of "how do you assert on
mail without a mailbox".

### 1. Mailpit is the mail server

The `mailpit` service in `docker-compose.yml` is an SMTP sink with an HTTP API
(`:8025`). `utils/mailpit.ts` wraps the part of that API these tests need.

```typescript
const { mail, dispose } = await createMailpitClient()
const message = await mail.waitForMessage(member.email)

expect(message.Subject).toContain('Vorabankündigung')
expect(message.ReplyTo.map((a) => a.Address)).toContain(KASSENWART)
```

### 2. The drain that sends is the production entrypoint

```typescript
import { drainMailQueue } from '../../utils/drain'

drainMailQueue()   // docker compose exec -T backend php /app/bin/cron.php
```

Never add a test-only sending path. `bin/cron.php` is what a hosting panel's
crontab calls, and it carries the `flock`, the wall-clock budget, the CLI-PHP
heartbeat and the retention pass. A shortcut around it would be green on a chain
nobody uses.

### 3. The transport is handed to that one run, not to the stack

`MAIL_DSN` is empty in `docker-compose.yml` and `drainMailQueue()` passes it with
`docker compose exec -e`. This is what keeps the suite deterministic: **a drain
claims the whole queue**, so a transport wired into the long-running backend
would let any spec that triggers a drain (the URL-trigger spec fires six) sweep
announcements another spec is asserting are still `pending`.

## Rules

| Rule | Why |
|------|-----|
| **Find the message by its recipient, never by index** | The inbox is global. `to:"<address>"` and a per-test generated address are the isolation (Pattern 003) |
| **Assert *exactly* N messages, not "at least one"** | A duplicate is the failure this whole harness exists to catch; `toBeGreaterThan(0)` passes on it |
| **Never `DELETE /api/v1/messages`** | A shared-state write against a mailbox other tests are reading — the failure Patterns 001 and 004 exist to prevent. Unique addresses mean there is nothing to clean up |
| **Poll, do not read once** | The drain returns when SMTP accepted the message; Mailpit indexes it a moment later. `waitForMessages()` uses `expect.poll` (Pattern 008) |
| **Prove a negative over a window** | `expectNothingFor()` holds the claim open for two seconds, so a message that should never have been sent has time to arrive and fail the test |
| **Keep the local part short** | RFC 5321 caps it at 64 characters, and a real SMTP server rejects a longer one — a generated id built from a long test name has done exactly that here |
| **Sending suites run in an ordered project** | `mail-chain` depends on `api-tests` and `admin-chromium`: every drain in the run happens inside the project that knows about them, and the `mail_config` singleton is written after the suites that also write it |

## Producing a delivery failure

A refused *connection* is transient by design — `SymfonyMailerTransport` reads
the first digit of the SMTP reply and treats an absent code as "not now" — so
the message waits out the retry ladder, an hour at the shortest tick, and no
test can wait for that. A **5xx** is what puts a row in `failed`, which is the
only status the retry button accepts.

Mailpit's chaos API (`MP_ENABLE_CHAOS=1` in the compose file) is how to get one:

```typescript
await mail.withRecipientsRefused(550, async () => {
  drainMailQueue()
})
```

It is global to the server and restored in a `finally`. Drain once *before*
creating the settlement under test, so the refusal lands on this test's own
message rather than on whatever the rest of the run left pending.

## Anti-patterns

```typescript
// ❌ Asserting on the outbox row instead of the message
expect(row.status).toBe('sent')          // our bookkeeping, not the member's inbox

// ❌ Taking the newest message
const [latest] = await mail.messagesTo(...)   // whose? four workers are sending

// ❌ Clearing the mailbox for a clean slate
await request.delete('/api/v1/messages')      // deletes other tests' evidence

// ❌ Calling the service directly to "just send it"
// there is no such path, and adding one tests something production never runs
```

## Related

- [Pattern 001: Test Data Isolation](pattern-001-test-data-isolation.md)
- [Pattern 003: Database-Agnostic Assertions](pattern-003-database-agnostic-assertions.md)
- [Pattern 004: Parallel Execution Safety](pattern-004-parallel-execution-safety.md)
- [ADR-0038](../../adr/0038-transactional-mail-outbox-on-shared-hosting.md) — the outbox, and why the scheduler is the only sender
