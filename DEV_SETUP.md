# Development Setup Guide

## Quick Start

```bash
# 1. Install backend dependencies
cd backend && composer install && cd ..

# 2. Start Docker containers (no build needed - code is mounted)
docker compose up -d

# 3. Migrate database and seed test data
curl -sf -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=migrate"
curl -sf -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=seed"

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
   - Re-seed: `curl -sf -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=seed"`

2. **Frontend**: `e2etests/config/test-credentials.ts`
   - Update the TEST_CREDENTIALS object to match seed.sql values

## Troubleshooting

### Tests fail with 401 Unauthorized
- Ensure database is seeded: `curl -sf -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=seed"`
- Verify credentials in both constant files are in sync

### Admin login fails
- Check seed data ran successfully
- Verify `admin@example.com` exists in database: `docker compose exec -T database mysql -u clubbar -pclubbar clubbar -e "SELECT email FROM admin_users;"`

### Terminal API authentication fails
- Check seed data ran successfully
- Verify test terminal exists: `docker compose exec -T database mysql -u clubbar -pclubbar clubbar -e "SELECT device_id, is_active FROM terminals;"`

### Database issues
- Full reset: `docker compose down -v && docker compose up -d` (deletes database volume), then re-run migrate and seed commands
- Migrate only: `curl -sf -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=migrate"`
- Check migration status: `curl -sf -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=status" | jq .`

## INSTALL_KEY Security

The install endpoint requires an `INSTALL_KEY` environment variable (at least 16 bytes) sent via the `X-Install-Key` header. In development, the key is set to `dev-install-key-x` in `.env.example`.

> **Production:** Replace `INSTALL_KEY` in your server's `.env` with a randomly generated secret of at least 16 characters before exposing the backend to any non-local network. Generate one with: `openssl rand -hex 24`

### Blocking install.php via Apache

`install.php` is blocked by `.htaccess` by default as a defence-in-depth measure. For local development, use the CLI instead:

```bash
# Preferred: Run migrations and seeds via CLI (no .htaccess config needed)
docker compose exec backend php artisan migrate
docker compose exec backend php artisan db:seed
```

If you must run migrations via HTTP (not recommended locally):
1. Temporarily comment out the `<Files "install.php">` block in `backend/public/.htaccess`
2. Call your migration endpoint: `curl -sf -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=migrate"`
3. **Immediately restore** the `<Files>` block in `.htaccess` (uncomment it)

## Backend Development

After changing backend code:
```bash
# PHP code changes (service/controller/etc)
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2

# Database schema changes — add new migration file to backend/db/migrations/, then:
curl -sf -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=migrate"

# New dependencies
cd backend && composer install
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
```