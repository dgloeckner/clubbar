# Club Bar End-to-End Tests

Playwright suites covering the API, the admin panel, the terminal UI, the mail
outbox and the release package. These are the project's integration safety net:
an E2E test here must exercise the whole stack — UI action → HTTP call → backend
→ database → visible result — not just that a form closed.

## Run them

Needs a running stack. [`scripts/dev-setup.sh`](../scripts/dev-setup.sh) brings
one up and installs both browsers (Chromium *and* WebKit — the mobile and
terminal projects default to WebKit).

```bash
cd e2etests
npm test                       # the default set, 4 workers
npm run test:api               # API projects only
npm run test:admin             # admin panel (chromium)
npm run test:terminal          # terminal touch UI (webkit)
npm test -- --grep "Settlements"
npm test -- --workers=1        # serial, for debugging isolation failures
```

Every run writes `results/latest.json`. Check it before re-running anything:

```bash
cat results/latest.json | jq '.stats'
node scripts/report-failures.mjs   # the same failure report CI prints
```

## When a run is red

`node scripts/report-failures.mjs` names each failing spec, its error and a
copy-pasteable re-run command. In CI the same report appears in the job log
tail, the job summary and as one annotation per failure — start there rather
than scrolling the log. Traces and HTML reports are uploaded per lane
(`api-1`, `api-2`, `ui-1`, `ui-2`, `chain`).

Passes with `--workers=1` but fails with 4? That is a test-isolation bug, not a
product bug — see Pattern 001 and Pattern 004.

## Layout

```
tests/         specs, grouped by surface (api, admin, admin-mobile, terminal,
               mail*, package, walkthrough)
pages/         page objects — the only place raw locators live
fixtures/      Playwright fixtures, including the authenticated page objects
config/        test credentials, shared by seed.sql and the specs
patterns/      the testing patterns; start with README.md
scripts/       failure reporting and CI-lane checks
```

## Before you write a test

**[`patterns/README.md`](./patterns/) is the index — read it first.** Eleven
patterns; these four are where most breakage comes from:

- **001 Test Data Isolation** — unique data per test, never shared mutable state
- **004 Parallel Execution Safety** — the suite runs 4 workers by default
- **006 Page Object Model** / **007 Page Object Fixtures** — no locators in specs
- **008 Playwright Assertions** — `await expect(locator).toBeVisible()`, never
  `try`/`catch` for a visibility check

See also [`../CLAUDE.md`](../CLAUDE.md) for the end-to-end integration
requirement and the test verification policy.

## License

Apache-2.0 (see the root [LICENSE](../LICENSE)).
