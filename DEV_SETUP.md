# Development Setup Guide

## Quick Start

```bash
# 1. Install backend dependencies
cd backend && composer install && cd ..

# 2. Start Docker containers (no build needed - code is mounted)
docker compose up -d

# 3. Migrate database and seed test data
curl -sf "http://localhost:8080/install.php?action=migrate&key=dev-install-key"
curl -sf "http://localhost:8080/install.php?action=seed&key=dev-install-key"

# 4. Install E2E test dependencies
cd e2etests && npm install

# 5. Run E2E tests
npm test
```

## Test Setup Details

### Automated Test Data

When you run the seed command, the following test data is automatically created:

#### Admin User
- **Email**: `admin@example.com`
- **Password**: `password123`
- **Purpose**: Admin panel authentication (session-based)
- **Source**: `backend/db/seed.sql`

#### Test Terminal
- **Device ID**: `test-device-001`
- **Name**: `Test Terminal`
- **API Token**: `test-terminal-token-do-not-use-in-production-0a1b2c3d4e5f6g7h`
- **Purpose**: Terminal API authentication (bearer token-based)
- **Source**: `backend/db/seed.sql`

### No Environment Variables Needed

Test credentials are hardcoded in:
- **Backend**: `backend/db/seed.sql` (seeds the database)
- **Frontend**: `e2etests/config/test-credentials.ts` (used by E2E tests)

Both files contain identical credentials to ensure consistency.

### Running Tests

```bash
# All tests (4 workers, parallel)
cd e2etests && npm test

# Specific test suite
npm test -- --grep "Transactions"

# Single test
npm test -- --grep "POST /api/sync/transactions accepts single transaction"

# Serial execution (useful for debugging)
npm test -- --workers=1
```

## Updating Test Credentials

If you need to change test credentials (e.g., for security), update both files:

1. **Backend**: `backend/db/seed.sql`
   - Update the credential values and password hash
   - Re-seed: `curl -sf "http://localhost:8080/install.php?action=seed&key=dev-install-key"`

2. **Frontend**: `e2etests/config/test-credentials.ts`
   - Update the TEST_CREDENTIALS object to match seed.sql values

## Troubleshooting

### Tests fail with 401 Unauthorized
- Ensure database is seeded: `curl -sf "http://localhost:8080/install.php?action=seed&key=dev-install-key"`
- Verify credentials in both constant files are in sync

### Admin login fails
- Check seed data ran successfully
- Verify `admin@example.com` exists in database: `docker compose exec -T database mysql -u clubbar -pclubbar clubbar -e "SELECT email FROM admin_users;"`

### Terminal API authentication fails
- Check seed data ran successfully
- Verify test terminal exists: `docker compose exec -T database mysql -u clubbar -pclubbar clubbar -e "SELECT device_id, is_active FROM terminals;"`

### Database issues
- Full reset: `docker compose down -v && docker compose up -d` (deletes database volume), then re-run migrate and seed commands
- Migrate only: `curl -sf "http://localhost:8080/install.php?action=migrate&key=dev-install-key"`
- Check migration status: `curl -sf "http://localhost:8080/install.php?action=status&key=dev-install-key" | jq .`

## Backend Development

After changing backend code:
```bash
# PHP code changes (service/controller/etc)
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2

# Database schema changes — add new migration file to backend/db/migrations/, then:
curl -sf "http://localhost:8080/install.php?action=migrate&key=dev-install-key"

# New dependencies
cd backend && composer install
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
```

## Architecture

- **Database**: MariaDB (in Docker)
- **Backend API**: PHP 8.3 / PDO (in Docker, code mounted from host)
- **Admin Panel**: React / TypeScript / Vite (runs on host or in Docker)
- **Terminal App**: Flutter / Dart (desktop app, runs on host)
- **Tests**: Playwright TypeScript (runs on host, connects to API)
- **Authentication**:
  - Admin: Session cookies
  - Terminal: Bearer token

See `backend/patterns/` for architectural patterns and `CLAUDE.md` for detailed conventions.
