# AGENTS.md

**[`CLAUDE.md`](./CLAUDE.md) is the single source of truth for this repository.**
Read it — everything that used to live here is in it.

This file exists so agents that look for `AGENTS.md` by convention find their way
there. It is deliberately a pointer and not a copy: the two documents drifted for
months while both claimed to be authoritative, and one of them ended up
describing a Laravel backend and an Electron terminal that this project has
never had.

## Where things are

| You want | Read |
|---|---|
| Project conventions, patterns, testing policy | [`CLAUDE.md`](./CLAUDE.md) |
| Domain vocabulary — Deckel, Storno, Jugendschutz, Limit, the roles | [`CONTEXT.md`](./CONTEXT.md) |
| Architectural decisions (binding) | [`adr/README.md`](./adr/README.md) |
| Getting a stack running | [`DEV_SETUP.md`](./DEV_SETUP.md) |
| Backend code patterns | [`backend/patterns/`](./backend/patterns/) |
| E2E testing patterns | [`e2etests/patterns/`](./e2etests/patterns/) |
| Admin frontend patterns | [`admin-frontend/patterns/`](./admin-frontend/patterns/) |
| Data model, flows, security, operations | [`docs/README.md`](./docs/README.md) |
| Functional requirements | [`use-cases/README.md`](./use-cases/README.md) |
| Current and past implementation plans | [`plans/INDEX.md`](./plans/INDEX.md) |

## The short version

- The backend is **PHP 8.3 on Slim 4**, not Laravel. There is no `artisan`.
  Run PHPUnit inside the container: `docker compose exec -w /app backend ./vendor/bin/phpunit`
- The terminal is **Flutter/Dart**, not Electron.
- Tests must be **verified green before committing** — see the Test Verification
  Policy in `CLAUDE.md`.
- Work on a **feature branch, land through a PR**, and link the issue.
- **ADRs are binding** and are not modified without explicit confirmation.
