# Club Bar Admin Frontend

The admin panel: members, product catalogue, transaction journal, SEPA
settlement, reports, GDPR workflows and the dashboard. React 19 + TypeScript on
Vite, with a typed API client generated from the OpenAPI spec.

Which sections an account can open depends on its role — `admin`,
`Kassenwart` or `Getränkewart` (see [ADR-0044](../adr/0044-tiered-admin-roles.md)).

## Run it

Needs the backend on `http://localhost:8080` — [`scripts/dev-setup.sh`](../scripts/dev-setup.sh)
brings one up.

```bash
cd admin-frontend
npm install
npm run dev        # http://localhost:5173, API proxied to :8080
npm run build      # -> dist/
```

## Test it

```bash
npm run test              # Vitest, watch
npm run test:unit         # single run
npm run test:timezones    # the suite under two timezones — date bugs hide here
npm run type-check        # tsc --noEmit, strict
npm run lint
```

Browser-level tests live in [`../e2etests/`](../e2etests/), not here.

## The API client is generated

Do not hand-write API calls. [`../api/admin.yaml`](../api/admin.yaml) is the
contract; orval generates the typed client from it. `src/api/generated/` is
git-ignored, not committed, and `npm install`/`npm ci` regenerate it via a
`postinstall` hook, so a fresh clone typechecks with no extra step. Run it by
hand after editing the spec, without a full reinstall:

```bash
npm run generate     # api/admin.yaml -> src/api/generated/
```

`src/api/client.ts` is the axios instance underneath — session cookie, CSRF
header, 401 handling — and the only place a file download may go through
(`downloadFile` / `downloadBlob`).

## Before you build a page

**[`patterns/README.md`](./patterns/) is the index — read it first.** The three
that are not optional:

- **Table Implementation** — `useListQuery` owns paging, sorting, filters,
  search debounce, request aborting and the post-mutation page clamp. Never
  hand-roll that state on a page.
- **Data Fetching** — any page that fetches on a filter, search, sort, page or
  interval must use `useLatestRequest` plus the generated `signal`, or it will
  render the results of a request it has already superseded.
- **Test IDs** — semantic `data-testid` attributes; the E2E suite selects on them.

Also [Role-Aware Navigation](./patterns/role-visibility.md) (an unclassified nav
entry fails the unit suite), [Date Field](./patterns/date-field.md) (`<input
type="date">` is used nowhere) and [Components](./patterns/components.md) —
check the index before writing a new component.

## Layout

```
src/
├── api/          client.ts (axios, auth, downloads) + generated/ (orval)
├── auth/         session handling
├── components/   common, forms, icons, layout, tables, modals + per-domain
│              (members, products, settings, settlements)
├── context/      AuthContext, LoadingContext
├── hooks/        useListQuery, useLatestRequest, useFormatters, useBreakpoint, …
├── i18n/         i18next setup (de/en)
├── pages/        one file per route
├── styles/       design-system.ts — theme tokens, all styling is CSS-in-JS
└── utils/        adminRoles.ts (SECTION_ROLES), formatters, icon resolution
```

Deeper architecture — auth flow, the generated-client contract, deployment —
is in [`technologies.md`](./technologies.md).

## License

Apache-2.0 (see the root [LICENSE](../LICENSE)).
