# Data Fetching Pattern — Cancellation and Stale-Response Guards

**Purpose**: Make sure a page renders the answer to the question it is currently
asking, and only that one.

---

## The Problem

Every list page in the admin panel refires its query on each keystroke, filter
click, sort toggle and page change. HTTP gives no ordering guarantee, so the
responses can come back in any order:

| Sequence | Result without a guard |
|----------|------------------------|
| Type `an` (500 ms debounce), then clear the search box — the cleared query fires with 0 ms delay | If `an` resolves last, the table shows filtered rows under an empty search box |
| Dashboard auto-refresh every 10 s on a slow backend | Tick N lands after tick N+1 and overwrites newer numbers with older ones |
| Switch the Reports tab from revenue to consumption | A slow revenue response overwrites the consumption tab |
| Navigate away mid-request | A resolved response calls `setState` on an unmounted component |

An `isMounted` ref only covers the last row. The first three are ordering
problems between two live requests, and only the request itself knows whether it
is still the current one.

---

## The Pattern

Use [`useLatestRequest`](../src/hooks/useLatestRequest.ts). It owns one
`AbortController` at a time:

- `next()` aborts whatever is in flight and returns the signal for the new request.
- `abort()` cancels the in-flight request without starting a new one — use it in effect cleanup.
- The in-flight request is aborted on unmount.

The generated orval client already takes a signal as its options argument
(`admin-frontend/src/api/client.ts` passes it straight to axios):

```typescript
const response = await getMembers().listMembers(params, { signal })
```

### Guard on `signal.aborted`, not on the abort error

Aborting usually makes axios reject, but not always: when the response has
already arrived by the time the abort lands, it resolves normally and no error
is ever thrown. Checking the flag covers both paths; catching `CanceledError`
covers only one.

```typescript
async function loadMembers(signal: AbortSignal = listRequest.next()) {
  try {
    setLoading(true)
    const response = await getMembers().listMembers(buildParams(), { signal })
    if (signal.aborted) return              // superseded — a newer request owns the state
    setMembers(response.data ?? [])
  } catch (err) {
    if (signal.aborted) return
    setError(err instanceof Error ? err.message : 'Failed to load members')
  } finally {
    // A superseded request must not clear the spinner the newer one raised.
    if (!signal.aborted) setLoading(false)
  }
}
```

### Claim the signal before the debounce, not inside it

The stale-search bug depends on the *cleared* query firing at 0 ms while the
debounced one is still in flight. Calling `next()` in the effect body kills the
old request the moment the search term changes, long before the new one is sent.

```typescript
useEffect(() => {
  const signal = listRequest.next()
  const timer = setTimeout(() => loadMembers(signal), search ? 500 : 0)
  return () => {
    clearTimeout(timer)
    listRequest.abort()
  }
}, [page, search, sortKey, sortDirection])
```

### One slot per independent stream

A page that loads a list *and* a set of dashboard metrics needs two hooks. A
single slot would have each load cancel the other:

```typescript
const listRequest = useLatestRequest()
const metricsRequest = useLatestRequest()
```

The Reports page follows the same rule with one slot per tab.

### Whoever aborts owns the spinner

If a mutation handler reloads the list through the same slot, it aborts the
loader effect's request — and that request's `finally` is then skipped. Keep
`setLoading(true)`/`setLoading(false)` inside the fetch function itself, so the
request that took over is also the one that clears the spinner. Putting the
spinner in the effect leaves it turning forever on the request that was cancelled.

---

## Checklist

- [ ] One `useLatestRequest()` per independent stream on the page
- [ ] `next()` called in the effect body, before any debounce timer
- [ ] Effect cleanup calls `abort()` alongside `clearTimeout`
- [ ] `{ signal }` passed to every generated client call
- [ ] `if (signal.aborted) return` before the success setters
- [ ] `if (signal.aborted) return` at the top of the `catch`
- [ ] `finally` guarded with `if (!signal.aborted)`
- [ ] Loading state raised and cleared inside the fetch function, not the effect

---

## Anti-Patterns

```typescript
// ❌ isMounted ref: guards unmount, not ordering. Two live requests still race.
if (isMountedRef.current) setMembers(response.data ?? [])

// ❌ Catching the abort error only: misses the response that already arrived.
catch (err) { if (axios.isCancel(err)) return; setError(...) }

// ❌ Unguarded finally: the stale request clears the spinner the new one raised.
finally { setLoading(false) }

// ❌ next() inside the debounce callback: the old request survives the keystroke.
setTimeout(() => load(listRequest.next()), 500)
```

---

## Related

- [`useLatestRequest`](../src/hooks/useLatestRequest.ts) and its unit tests
- [`useExecutionDateInfo`](../src/hooks/useExecutionDateInfo.ts) — the same
  guarantee for a single one-shot fetch, using a `cancelled` flag
- [Table Implementation Pattern](./table-implementation.md) — the list pages this applies to

---

## Version History

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-08-08 | Initial pattern, extracted from the #96 fix |
