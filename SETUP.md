# Development Setup Guide

## Quick Start

```bash
# 1. Install backend dependencies
cd backend && composer install && cd ..

# 2. Start Docker containers (no build needed - code is mounted)
docker compose up -d

# 3. Migrate database and seed test data
docker compose exec -T backend sh -c "cd /app && php artisan migrate:fresh --seed"

# 4. Install E2E test dependencies
cd e2etests && npm install

# 5. Run E2E tests
npm test
```

## Test Setup Details

### Automated Test Data

When you run `php artisan migrate:fresh --seed`, the following test data is automatically created:

#### Admin User
- **Email**: `admin@example.com`
- **Password**: `password123`
- **Purpose**: Admin panel authentication (session-based)
- **Source**: `backend/app/Shared/Constants/TestCredentials.php`

#### Test Terminal
- **Device ID**: `test-device-001`
- **Name**: `Test Terminal`
- **API Token**: `test-terminal-token-do-not-use-in-production-0a1b2c3d4e5f6g7h`
- **Purpose**: Terminal API authentication (bearer token-based)
- **Source**: `backend/app/Shared/Constants/TestCredentials.php`

### No Environment Variables Needed

Test credentials are hardcoded in:
- **Backend**: `backend/app/Shared/Constants/TestCredentials.php`
- **Frontend**: `e2etests/config/test-credentials.ts`

Both files contain identical credentials to ensure consistency.

### Running Tests

```bash
# All tests
npm test

# Specific test suite
npm test -- --grep "Transactions"

# Single test
npm test -- --grep "POST /api/sync/transactions accepts single transaction"

# With serial execution (useful for debugging)
npm test -- --workers=1
```

## Updating Test Credentials

If you need to change test credentials (e.g., for security), update both files:

1. **Backend**: `backend/app/Shared/Constants/TestCredentials.php`
   - Update the constant values
   - Run `docker compose exec -T backend sh -c "cd /app && php artisan migrate:fresh --seed"`

2. **Frontend**: `e2etests/config/test-credentials.ts`
   - Update the TEST_CREDENTIALS object to match backend values

## Troubleshooting

### Tests fail with 401 Unauthorized
- Ensure database is seeded: `docker compose exec -T backend sh -c "cd /app && php artisan migrate:fresh --seed"`
- Verify credentials in both constant files are in sync

### Admin login fails
- Check AdminUsersSeeder ran successfully
- Verify `admin@example.com` exists in database: `docker compose exec -T database mysql -u ruderbar -pruderbar ruderbar -e "SELECT email FROM admin_users;"`

### Terminal API authentication fails
- Check TerminalSeeder ran successfully
- Verify test terminal exists: `docker compose exec -T database mysql -u ruderbar -pruderbar ruderbar -e "SELECT device_id, is_active FROM terminals;"`

### Database issues
- Full reset: `docker compose exec -T backend sh -c "cd /app && php artisan migrate:fresh --seed"`
- Or: `docker compose down -v && docker compose up -d` (deletes database volume)

## Backend Development

After changing backend code:
```bash
# PHP code changes (service/controller/etc)
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2

# Database schema changes
docker compose exec -T backend sh -c "cd /app && php artisan migrate"

# New dependencies
cd backend && composer install
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
```

## Architecture

- **Database**: MariaDB (in Docker)
- **Backend API**: Laravel/PHP (in Docker, code mounted from host)
- **Tests**: Playwright TypeScript (runs on host, connects to API)
- **Authentication**:
  - Admin: Session cookies (Pattern 013)
  - Terminal: Bearer token (Pattern 012)

See `backend/patterns/` for architectural patterns and `CLAUDE.md` for detailed conventions.
