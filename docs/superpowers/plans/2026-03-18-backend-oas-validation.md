# Backend OAS Validation Middleware Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `league/openapi-psr7-validator` as a PSR-15 middleware to the Slim 4 backend that validates every terminal API response against `api/terminal.yaml` during CI test runs, making spec drift fail Playwright tests automatically.

**Architecture:** A path-filtering PSR-15 wrapper (`TerminalOasValidator`) wraps the OAS validator middleware and only activates it for terminal API paths (`/api/health`, `/api/sync/*`, `/api/terminal/*`). Admin routes (`/api/admin/*`) are passed through untouched — they are covered by `admin.yaml`, which is out of scope. The wrapper is conditionally registered in `backend/bootstrap.php` when `APP_ENV=test`. `docker-compose.ci.yml` sets `APP_ENV=test` for CI runs. No changes to controllers, services, repositories, or `routes.php`.

**Tech Stack:** PHP 8.3, Slim 4, `league/openapi-psr7-validator` (latest stable via Composer), PSR-15 middleware stack

---

## File Map

| File | Change |
|---|---|
| `api/terminal.yaml` | Fix server base URLs (add `/api` prefix) and transaction history path |
| `backend/composer.json` | Add `league/openapi-psr7-validator` dependency |
| `backend/src/Shared/Middleware/TerminalOasValidator.php` | New: path-filtering wrapper around the OAS validator |
| `backend/src/ServiceFactory.php` | Add `getTerminalOasValidator()` factory method |
| `backend/bootstrap.php` | Register `TerminalOasValidator` after `add(ErrorHandler)`, guarded by `APP_ENV=test` |
| `docker-compose.ci.yml` | Add `APP_ENV: test` to `backend` service environment block |

---

## Chunk 1: Fix Spec Paths, Install, Wire, and Activate

### Task 1: Fix `api/terminal.yaml` to match actual backend routes

`league/openapi-psr7-validator` resolves which spec operation to validate against by stripping the server base URL from the incoming request URI. Two corrections are needed before the validator can match real requests:

**Problem 1 — Server base missing `/api` prefix:**
The spec's `servers` block lists `http://localhost:8080` but all actual routes are prefixed with `/api` (e.g., `/api/health`, `/api/sync/members`). The validator would strip `http://localhost:8080` and look up `/api/health` in the spec, where only `/health` exists → `NoPath` error on every terminal request.

**Problem 2 — Transaction history path mismatch:**
The spec defines the transaction history endpoint as `/transactions/{member_id}`. The actual route in `routes.php` is `/api/terminal/transactions/{memberId}`. After fixing the server base (Problem 1), the validator would resolve this as `/terminal/transactions/{memberId}` — still a mismatch with `/transactions/{member_id}`.

**Files:**
- Modify: `api/terminal.yaml`

- [ ] **Step 1: Update `servers` block to include `/api` base path**

Find the `servers:` block near the top of `api/terminal.yaml` (around line 67) and update both entries:

```yaml
servers:
  - url: https://api.example.com/api
    description: Production server (HTTPS only)
  - url: http://localhost:8080/api
    description: Local development (Docker)
```

- [ ] **Step 2: Rename the transaction history path**

Find the path key `/transactions/{member_id}:` (around line 507) and rename it to `/terminal/transactions/{member_id}:`.

Also update any internal `$ref` or `summary` text that references this path if needed (search for `/transactions/{member_id}` in the file).

- [ ] **Step 3: Verify the spec parses cleanly**

```bash
cd backend && php -r "
require 'vendor/autoload.php';
use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
\$path = realpath(__DIR__ . '/../api/terminal.yaml');
\$builder = (new ValidationMiddlewareBuilder())->fromYamlFile(\$path);
echo 'Spec parsed OK' . PHP_EOL;
"
```

If `vendor/` is not yet populated (Task 2 runs later), skip this step and come back to it after Task 2. The verification is important — it confirms the spec is valid YAML and parseable by the validator.

- [ ] **Step 4: Commit**

```bash
git add api/terminal.yaml
git commit -m "fix(spec): align terminal.yaml server base and transaction history path with actual backend routes"
```

---

### Task 2: Install `league/openapi-psr7-validator`

**Files:**
- Modify: `backend/composer.json`

- [ ] **Step 1: Install the package (latest stable)**

```bash
cd backend && composer require league/openapi-psr7-validator
```

Expected: `composer.lock` updated, `vendor/` populated. No conflicts printed. Composer will install the latest stable version.

If installation fails due to a conflict with existing packages, run `composer why-not league/openapi-psr7-validator` to diagnose.

- [ ] **Step 2: Verify class is loadable**

```bash
cd backend && php -r "require 'vendor/autoload.php'; echo class_exists('League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder') ? 'OK' : 'FAIL'; echo PHP_EOL;"
```

Expected output: `OK`

- [ ] **Step 3: Commit**

```bash
git add backend/composer.json backend/composer.lock
git commit -m "feat(backend): install league/openapi-psr7-validator for OAS contract validation"
```

---

### Task 3: Create `TerminalOasValidator` middleware

`terminal.yaml` only covers the terminal API paths. The backend also serves admin routes (`/api/admin/*`) covered by `admin.yaml` (out of scope). The raw OAS validator throws `NoPath` for any route not in its spec, which would break all admin Playwright tests. `TerminalOasValidator` wraps the validator and only activates it for terminal paths.

**Files:**
- Create: `backend/src/Shared/Middleware/TerminalOasValidator.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps league/openapi-psr7-validator and only activates it for terminal API paths.
 *
 * Admin routes (/api/admin/*) are not in terminal.yaml and must be passed through
 * untouched. Without this guard, the OAS validator throws NoPath on any admin route.
 */
class TerminalOasValidator implements MiddlewareInterface
{
    private const TERMINAL_PREFIXES = [
        '/api/health',
        '/api/sync',
        '/api/terminal',
    ];

    public function __construct(
        private readonly MiddlewareInterface $validator
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $path = $request->getUri()->getPath();

        foreach (self::TERMINAL_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $this->validator->process($request, $handler);
            }
        }

        // Not a terminal path — bypass OAS validation entirely
        return $handler->handle($request);
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
cd backend && php -l src/Shared/Middleware/TerminalOasValidator.php
```

Expected: `No syntax errors detected`

---

### Task 4: Add factory method to `ServiceFactory`

**Files:**
- Modify: `backend/src/ServiceFactory.php`

- [ ] **Step 1: Add `use` imports** at the top of `backend/src/ServiceFactory.php`, alongside the other middleware `use` statements:

```php
use App\Shared\Middleware\TerminalOasValidator;
use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
```

- [ ] **Step 2: Add the factory method** after the existing `get*Middleware()` methods:

```php
public function getTerminalOasValidator(): \Psr\Http\Server\MiddlewareInterface
{
    $specPath = realpath(__DIR__ . '/../../api/terminal.yaml');
    if ($specPath === false) {
        throw new \RuntimeException('OAS spec not found: api/terminal.yaml');
    }
    $validator = (new ValidationMiddlewareBuilder())
        ->fromYamlFile($specPath)
        ->getValidationMiddleware();

    return new TerminalOasValidator($validator);
}
```

Note: `__DIR__` in `backend/src/ServiceFactory.php` is `backend/src/`. The path `../../api/terminal.yaml` resolves to the repo-root `api/terminal.yaml`. ✓

- [ ] **Step 3: Verify syntax**

```bash
cd backend && php -l src/ServiceFactory.php
```

Expected: `No syntax errors detected in src/ServiceFactory.php`

Note: `TerminalOasValidator` is intentionally **not** added to the container's FQCN map. It is only ever instantiated via `getTerminalOasValidator()` and is never resolved by Slim's container routing.

---

### Task 5: Register middleware in `bootstrap.php`

**Files:**
- Modify: `backend/bootstrap.php`

- [ ] **Step 1: Add conditional middleware registration**

In `backend/bootstrap.php`, insert the following block immediately **after** the `$app->add($factory->getErrorHandler());` line and **before** `$app->add($factory->getJsonBodyParser());`:

```php
// OAS contract validation for terminal API paths — active in test environment only.
// Slim 4 adds middleware in FIFO order (first added = outermost = first to handle request).
// Placing this AFTER getErrorHandler() means it executes inside the error handler:
// ErrorHandler → TerminalOasValidator → JsonBodyParser → CorsMiddleware → route handler
// So validation exceptions are caught and formatted by ErrorHandler.
if (getenv('APP_ENV') === 'test') {
    $app->add($factory->getTerminalOasValidator());
}
```

The full middleware block in `bootstrap.php` should read:

```php
$app->addRoutingMiddleware();
$app->add($factory->getErrorHandler());           // outermost — first added
if (getenv('APP_ENV') === 'test') {
    $app->add($factory->getTerminalOasValidator()); // second — inside error handler
}
$app->add($factory->getJsonBodyParser());
$app->add($factory->getCorsMiddleware());          // innermost — last added
```

- [ ] **Step 2: Verify syntax**

```bash
cd backend && php -l bootstrap.php
```

Expected: `No syntax errors detected in bootstrap.php`

---

### Task 6: Set `APP_ENV=test` in `docker-compose.ci.yml`

**Files:**
- Modify: `docker-compose.ci.yml`

- [ ] **Step 1: Add `environment:` block to the existing `backend:` service stanza**

The current file has:
```yaml
  backend:
    platform: linux/amd64
```

Add `environment:` below `platform:` (do not remove `platform:` or the comment header):
```yaml
  backend:
    platform: linux/amd64
    environment:
      APP_ENV: test
```

The full resulting file:
```yaml
# CI overrides for docker-compose.yml
# Removes ARM-specific platform constraints so images resolve to the runner's native arch

services:
  database:
    platform: linux/amd64
  backend:
    platform: linux/amd64
    environment:
      APP_ENV: test
```

- [ ] **Step 2: Commit all changes**

```bash
git add backend/src/Shared/Middleware/TerminalOasValidator.php \
        backend/src/ServiceFactory.php \
        backend/bootstrap.php \
        docker-compose.ci.yml
git commit -m "feat(backend): register TerminalOasValidator middleware for APP_ENV=test"
```

---

## Chunk 2: Verify Middleware Works

### Task 7: Verify existing Playwright tests pass with middleware active

This confirms the middleware validates and accepts all current terminal API responses without breaking admin tests.

- [ ] **Step 1: Start the stack with CI overrides (activates APP_ENV=test)**

```bash
docker compose -f docker-compose.yml -f docker-compose.ci.yml up -d
sleep 3
```

- [ ] **Step 2: Verify backend health**

```bash
curl -s http://localhost:8080/api/health | jq .
```

Expected: `{"status":"ok","timestamp":"..."}` — not an HTML error page.

- [ ] **Step 3: Restart PHP to pick up code changes**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
```

- [ ] **Step 4: Run the full Playwright test suite**

```bash
cd e2etests && npm test -- --workers=4
```

Expected: All tests pass.

If any terminal-route test fails with a 5xx, check backend logs for the OAS validator error:
```bash
TODAY=$(date +%Y-%m-%d)
docker compose exec backend cat /app/logs/$TODAY.log | jq 'select(.level == "ERROR" or .level == "CRITICAL")'
```

A `RequestValidationFailed` or `ResponseValidationFailed` message means the backend response doesn't match `terminal.yaml`. Fix the controller response to match the spec.

If an admin-route test fails with a `NoPath` error, the `TerminalOasValidator` path prefix check has a bug — verify the path in `TERMINAL_PREFIXES` against the actual request path in the logs.

---

### Task 8: Verify drift detection catches spec violations

This proves the middleware actually rejects invalid responses.

- [ ] **Step 1: Temporarily break the health endpoint response**

Open `backend/src/Shared/Controllers/HealthController.php`. Find the line that sets `status` in the response array and change `'status' => 'ok'` to `'status' => 12345` (integer instead of string — violates the `enum: [ok]` constraint in `terminal.yaml`).

- [ ] **Step 2: Restart PHP**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
```

- [ ] **Step 3: Run a health test**

```bash
cd e2etests && npm test -- --grep "health" --workers=1
```

Expected: Test **fails** with a 5xx or validation error. This confirms the middleware catches schema violations.

If the test passes unexpectedly (middleware not firing), confirm `APP_ENV=test` is set in the container:
```bash
docker compose exec backend printenv APP_ENV
```
Expected: `test`. If empty, the docker-compose.ci.yml override is not applied — verify you started with `-f docker-compose.ci.yml`.

- [ ] **Step 4: Revert `HealthController.php`**

Undo the change — restore `'status' => 'ok'`.

- [ ] **Step 5: Restart PHP and verify the test passes**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
cd e2etests && npm test -- --grep "health" --workers=1
```

Expected: Test passes.

- [ ] **Step 6: Commit**

```bash
git commit --allow-empty -m "test(backend): OAS validation middleware verified — catches drift, passes valid responses"
```
