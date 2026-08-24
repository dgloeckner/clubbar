# Club Bar Backend

The REST API behind the terminal and the admin panel: members, products,
transactions, SEPA settlement, mail, audit logging and the sync contract the
kiosk talks to. PHP 8.3 on Slim 4, MariaDB through PDO, no ORM.

## Run it

The backend is not run on its own — it comes up with the dev stack, and the
setup script does the whole thing (idempotent, safe to re-run):

```bash
scripts/dev-setup.sh
curl http://localhost:8080/api/health
```

Code is mounted into the container, so there is no image to rebuild. After
changing PHP:

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
```

See [`DEV_SETUP.md`](../DEV_SETUP.md) for credentials, migrations and troubleshooting.

## Test it

Run PHPUnit **inside the container**. The host has no `bcmath`, which
`Validator.php` needs for the IBAN checksum, and cannot resolve the `database`
host the feature tests connect to:

```bash
docker compose exec -w /app backend ./vendor/bin/phpunit
docker compose exec -w /app backend ./vendor/bin/phpunit --filter MembersServiceTest
composer test:coverage        # run from the host; enforces the coverage floor
```

API-level tests live in [`../e2etests/`](../e2etests/) and run against a live stack.

## Layout

```
src/
├── Modules/        one directory per domain (Members, Settlements, Terminals, …),
│                   each with Controllers / Services / Repositories / DTOs (ADR-0018)
├── Shared/         cross-module infrastructure: Http, Validation, Security, Mail,
│                   Logging, Middleware, Exceptions, Sync
├── ServiceFactory  manual DI container (PSR-11)
└── routes.php      URL → controller mapping
db/                 migrations and seed.sql
bin/cron.php        scheduler: drains the mail outbox, sends the Deckelauszug,
                    runs terminal anomaly detection
```

## Before you write code

- **[`patterns/README.md`](./patterns/) — read this first.** Eighteen patterns
  covering validation, DTOs, services, repositories, auth, authorization,
  audit logging and the shared HTTP layer. All backend work must follow them.
- [`technologies.md`](./technologies.md) — stack, architecture layers, request flow
- [`../api/`](../api/) — the OpenAPI specs are the API contract; update them with the code
- [`../adr/`](../adr/) — binding architectural decisions
- [`../CLAUDE.md`](../CLAUDE.md) — project conventions

## License

Apache-2.0 (see the root [LICENSE](../LICENSE)).
