# Laravel to Lightweight PHP Stack — Migration Plan

## Purpose

This document is an actionable migration plan for an AI agent to migrate an existing Laravel application to a minimal, dependency-light PHP stack suitable for IONOS shared hosting (restricted SSH or no SSH). Every section contains concrete mappings, code templates, and validation steps.

---

## 1. Target Stack Overview

| Concern | Laravel | Target | Dependencies |
|---|---|---|---|
| Package management | Composer | Composer | — |
| HTTP routing + middleware | `illuminate/routing` | **Slim 4** | `slim/slim`, `slim/psr7` |
| DI container | `illuminate/container` | **Manual factory** | None |
| Database access | Eloquent ORM | **PDO** | Built-in |
| Migrations | `artisan migrate` | **Plain SQL + runner** | None |
| Logging | `illuminate/log` (Monolog) | **DIY Logger** | None |
| Auth (session) | Laravel session guard | **PSR-15 middleware** | Built-in PHP sessions |
| Auth (API token) | Sanctum / Passport | **PSR-15 middleware + SHA-256 tokens** | None |
| Configuration | `.env` + `config/*.php` | **DIY Env + AppConfig** | None |
| Validation | `illuminate/validation` | **DIY or manual** | None |
| Error handling | Laravel exception handler | **Slim error middleware** | None |
| CLI commands | Artisan | **Web-based installer endpoint** | None |

### Final `composer.json`

```json
{
  "require": {
    "php": ">=8.1",
    "slim/slim": "^4.0",
    "slim/psr7": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

---

## 2. Target Project Structure

```
project-root/
├── .env                        # secrets + env config (NOT committed)
├── .env.example                # template (committed)
├── .gitignore
├── composer.json
├── composer.lock
│
├── db/
│   ├── migrations/
│   │   ├── 001_create_users.sql
│   │   ├── 002_create_api_tokens.sql
│   │   └── ...
│   └── MigrationRunner.php
│
├── logs/                       # daily rotated log files
│
├── public/
│   ├── .htaccess
│   ├── index.php               # app entry point
│   └── install.php             # migration installer (removable)
│
├── src/
│   ├── Config/
│   │   ├── Env.php
│   │   ├── AppConfig.php
│   │   ├── ServiceFactory.php
│   │   └── routes.php
│   │
│   ├── Controller/             # thin REST controllers
│   │   └── ...
│   │
│   ├── Middleware/
│   │   ├── SessionAuthMiddleware.php
│   │   ├── ApiTokenMiddleware.php
│   │   ├── RequestLoggerMiddleware.php
│   │   └── CorsMiddleware.php
│   │
│   ├── Service/                # business logic
│   │   ├── AuthService.php
│   │   ├── ApiTokenService.php
│   │   └── ...
│   │
│   ├── Repository/             # data access (PDO)
│   │   ├── SafeQuery.php       # SQL injection prevention helpers
│   │   └── ...
│   │
│   ├── Logging/
│   │   └── Logger.php
│   │
│   └── Validation/
│       └── Validator.php
│
└── storage/
    └── install.lock
```

---

## 3. Migration Steps — Ordered Checklist

Execute in this exact order. Each step should be validated before proceeding.

### Phase 1: Scaffold Target Structure

- [ ] Create the directory structure shown above
- [ ] Create `composer.json` with only `slim/slim` and `slim/psr7`
- [ ] Run `composer install`
- [ ] Create `.env.example` and `.env` (copy values from Laravel `.env`)
- [ ] Create `.gitignore` (see Section 12)
- [ ] Create `public/.htaccess` (see Section 11)

### Phase 2: Foundation Classes

- [ ] Implement `src/Config/Env.php` (see Section 5)
- [ ] Implement `src/Config/AppConfig.php` (see Section 5)
- [ ] Implement `src/Logging/Logger.php` (see Section 6)
- [ ] Implement `db/MigrationRunner.php` (see Section 7)
- [ ] Implement `public/install.php` (see Section 7)

### Phase 3: Migrate Database Schema

- [ ] Extract all Laravel migration files from `database/migrations/`
- [ ] Convert each to plain SQL in `db/migrations/` (see Section 7)
- [ ] Number sequentially: `001_`, `002_`, etc.
- [ ] Preserve the order of the original Laravel migrations
- [ ] Validate SQL syntax against MySQL/MariaDB

### Phase 4: Migrate Models → Repositories

- [ ] For each Eloquent model in `app/Models/`:
  - Create a repository class in `src/Repository/`
  - Replace Eloquent methods with PDO prepared statements
  - Preserve all query logic (scopes, relationships as explicit JOINs)
- [ ] See Section 8 for mapping reference

### Phase 5: Migrate Services

- [ ] For each class in `app/Services/` (or business logic in controllers):
  - Create corresponding class in `src/Service/`
  - Replace facade calls with injected dependencies
  - Replace Eloquent calls with repository method calls
- [ ] Migrate `app/Http/Controllers/` logic split into Controller (thin) + Service (logic)

### Phase 6: Migrate Authentication

- [ ] Implement `src/Service/AuthService.php` (see Section 9)
- [ ] Implement `src/Service/ApiTokenService.php` (see Section 9)
- [ ] Implement `src/Middleware/SessionAuthMiddleware.php` (see Section 9)
- [ ] Implement `src/Middleware/ApiTokenMiddleware.php` (see Section 9)
- [ ] Migrate user password hashes (Laravel uses `bcrypt` by default — compatible with `password_verify`)

### Phase 7: Migrate Routes

- [ ] Translate `routes/web.php` and `routes/api.php` into `src/Config/routes.php`
- [ ] Map Laravel route groups and middleware to Slim groups + middleware
- [ ] See Section 10 for mapping reference

### Phase 8: Wire Everything Together

- [ ] Implement `src/Config/ServiceFactory.php` with all dependencies
- [ ] Implement `public/index.php` entry point
- [ ] Verify all classes are autoloaded via PSR-4

### Phase 9: Migrate Remaining Components

- [ ] Migrate validation logic (see Section 13)
- [ ] Migrate error/exception handling (see Section 14)
- [ ] Migrate any CORS handling (see Section 15)
- [ ] Migrate request logging (see Section 16)

### Phase 10: Validate and Deploy

- [ ] Test all endpoints against the Laravel originals
- [ ] Run migration installer against a test database
- [ ] Verify `.env` is not accessible via web
- [ ] Verify logs are written correctly
- [ ] Remove or disable `public/install.php` after migration

---

## 4. Entry Point — `public/index.php`

```php
<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use App\Config\Env;
use App\Config\AppConfig;
use App\Config\ServiceFactory;
use App\Logging\Logger;

require __DIR__ . '/../vendor/autoload.php';

// --- Configuration ---
Env::load(__DIR__ . '/../.env');
Env::require(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV']);

$config = new AppConfig();

// --- Infrastructure ---
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASS'),
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

$logger = new Logger($config->logDir, $config->debug ? 'DEBUG' : 'INFO');

// --- Application ---
$factory = new ServiceFactory($pdo, $logger, $config);

$app = AppFactory::create();

// Global middleware (outermost first)
$app->add($factory->requestLogger());
$app->addBodyParsingMiddleware();

// Error handling
$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: $config->debug,
    logErrors: true,
    logErrorDetails: true
);

// Routes
(require __DIR__ . '/../src/Config/routes.php')($app, $factory);

$app->run();
```

---

## 5. Configuration — `Env` and `AppConfig`

### `src/Config/Env.php`

```php
<?php

declare(strict_types=1);

namespace App\Config;

class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!file_exists($file)) {
            throw new \RuntimeException(".env not found: {$file}");
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            if (!str_contains($line, '=')) continue;

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");

            self::$vars[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        return self::$vars[$key]
            ?? $_ENV[$key]
            ?? getenv($key) ?: $default
            ?? throw new \RuntimeException("Missing env var: {$key}");
    }

    public static function require(array $keys): void
    {
        $missing = array_filter($keys, fn($k) =>
            !isset(self::$vars[$k]) && !isset($_ENV[$k]) && !getenv($k)
        );
        if ($missing) {
            throw new \RuntimeException('Missing required env vars: ' . implode(', ', $missing));
        }
    }
}
```

### `src/Config/AppConfig.php`

```php
<?php

declare(strict_types=1);

namespace App\Config;

class AppConfig
{
    public readonly string $env;
    public readonly bool   $debug;
    public readonly int    $sessionMaxAge;
    public readonly int    $sessionRegenInterval;
    public readonly int    $tokenTtlDays;
    public readonly string $logDir;
    public readonly string $installKey;

    public function __construct()
    {
        $this->env                  = Env::get('APP_ENV', 'production');
        $this->debug                = filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
        $this->sessionMaxAge        = (int) Env::get('SESSION_MAX_AGE', '3600');
        $this->sessionRegenInterval = (int) Env::get('SESSION_REGEN_INTERVAL', '900');
        $this->tokenTtlDays         = (int) Env::get('API_TOKEN_TTL_DAYS', '90');
        $this->logDir               = __DIR__ . '/../../logs';
        $this->installKey           = Env::get('INSTALL_KEY', '');
    }

    public function isProduction(): bool
    {
        return $this->env === 'production';
    }
}
```

### `.env.example`

```bash
# Application
APP_ENV=development
APP_DEBUG=true

# Database
DB_HOST=
DB_NAME=
DB_USER=
DB_PASS=

# Security
INSTALL_KEY=
SESSION_MAX_AGE=3600
SESSION_REGEN_INTERVAL=900
API_TOKEN_TTL_DAYS=90

# Mail (if needed)
SMTP_HOST=
SMTP_USER=
SMTP_PASS=
```

### Laravel `.env` Mapping

| Laravel key | Target key | Notes |
|---|---|---|
| `APP_ENV` | `APP_ENV` | Direct |
| `APP_DEBUG` | `APP_DEBUG` | Direct |
| `APP_KEY` | — | Not needed, no encryption layer |
| `DB_CONNECTION` | — | Hardcoded `mysql` in PDO DSN |
| `DB_HOST` | `DB_HOST` | Direct |
| `DB_PORT` | `DB_PORT` | Optional, append to DSN if non-default |
| `DB_DATABASE` | `DB_NAME` | Renamed |
| `DB_USERNAME` | `DB_USER` | Renamed |
| `DB_PASSWORD` | `DB_PASS` | Renamed |
| `CACHE_DRIVER` | — | No cache abstraction |
| `SESSION_DRIVER` | — | Always PHP native sessions |
| `LOG_CHANNEL` | — | Always file-based |

---

## 6. Logging — `Logger`

### `src/Logging/Logger.php`

```php
<?php

declare(strict_types=1);

namespace App\Logging;

class Logger
{
    private const LEVELS = [
        'DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3, 'CRITICAL' => 4
    ];

    private string $logDir;
    private string $minLevel;
    private string $channel;

    public function __construct(string $logDir, string $minLevel = 'DEBUG', string $channel = 'app')
    {
        $this->logDir   = rtrim($logDir, '/');
        $this->minLevel = $minLevel;
        $this->channel  = $channel;

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function debug(string $msg, array $ctx = []): void    { $this->log('DEBUG', $msg, $ctx); }
    public function info(string $msg, array $ctx = []): void     { $this->log('INFO', $msg, $ctx); }
    public function warning(string $msg, array $ctx = []): void  { $this->log('WARNING', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void    { $this->log('ERROR', $msg, $ctx); }
    public function critical(string $msg, array $ctx = []): void { $this->log('CRITICAL', $msg, $ctx); }

    private function log(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }

        $entry = json_encode([
            'ts'      => date('c'),
            'level'   => $level,
            'channel' => $this->channel,
            'msg'     => $message,
            'ctx'     => $context ?: null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $file = $this->logDir . '/' . date('Y-m-d') . '.log';
        file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }

    public function purge(int $retainDays = 30): int
    {
        $deleted = 0;
        $cutoff = strtotime("-{$retainDays} days");

        foreach (glob($this->logDir . '/*.log') as $file) {
            $dateStr = basename($file, '.log');
            if (strtotime($dateStr) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }
        return $deleted;
    }
}
```

### Laravel Logging Migration

| Laravel usage | Target equivalent |
|---|---|
| `Log::info('msg')` | `$this->logger->info('msg')` |
| `Log::error('msg', ['key' => $val])` | `$this->logger->error('msg', ['key' => $val])` |
| `Log::channel('slack')->critical(...)` | Not supported — file only |
| `logger()->debug(...)` | `$this->logger->debug(...)` |
| `report($exception)` | `$this->logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()])` |

**Key difference:** No facades or global helpers. Logger is always injected via constructor.

---

## 7. Database Migrations

### `db/MigrationRunner.php`

```php
<?php

declare(strict_types=1);

namespace App\Db;

use PDO;

class MigrationRunner
{
    private PDO $db;
    private array $log = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS _migrations (
            file VARCHAR(255) PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            applied_by VARCHAR(255)
        )');
    }

    public function migrate(string $dir, string $appliedBy = 'installer'): array
    {
        $applied = $this->db->query('SELECT file, checksum FROM _migrations')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $files = glob($dir . '/*.sql');
        sort($files);

        $this->db->beginTransaction();

        try {
            foreach ($files as $file) {
                $name     = basename($file);
                $sql      = file_get_contents($file);
                $checksum = hash('sha256', $sql);

                if (isset($applied[$name])) {
                    if ($applied[$name] !== $checksum) {
                        throw new \RuntimeException(
                            "INTEGRITY VIOLATION: {$name} has been modified after application. " .
                            "Expected checksum {$applied[$name]}, got {$checksum}."
                        );
                    }
                    $this->log[] = ['status' => 'SKIP', 'file' => $name, 'reason' => 'already applied'];
                    continue;
                }

                $this->db->exec($sql);

                $stmt = $this->db->prepare('INSERT INTO _migrations (file, checksum, applied_by) VALUES (?, ?, ?)');
                $stmt->execute([$name, $checksum, $appliedBy]);

                $this->log[] = ['status' => 'OK', 'file' => $name];
            }

            $this->db->commit();
            $this->log[] = ['status' => 'DONE', 'message' => 'All migrations applied'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->log[] = ['status' => 'FAIL', 'message' => $e->getMessage()];
            $this->log[] = ['status' => 'ROLLBACK', 'message' => 'Transaction rolled back'];
        }

        return $this->log;
    }

    public function status(string $dir): array
    {
        $applied = $this->db->query('SELECT file, applied_at FROM _migrations ORDER BY file')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $files = glob($dir . '/*.sql');
        sort($files);

        $status = [];
        foreach ($files as $file) {
            $name = basename($file);
            $status[] = [
                'file'    => $name,
                'applied' => isset($applied[$name]),
                'date'    => $applied[$name] ?? null,
            ];
        }
        return $status;
    }
}
```

### `public/install.php`

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Env;
use App\Db\MigrationRunner;

Env::load(__DIR__ . '/../.env');

// --- Access control ---
$installKey  = Env::get('INSTALL_KEY');
$providedKey = $_SERVER['HTTP_X_INSTALL_KEY'] ?? $_GET['key'] ?? '';

if ($installKey === '' || !hash_equals($installKey, $providedKey)) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Forbidden']));
}

// --- Concurrency lock ---
$lockFile = __DIR__ . '/../storage/install.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
    http_response_code(429);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Install locked. Retry later.']));
}
file_put_contents($lockFile, date('c'));

// --- Run ---
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$runner = new MigrationRunner($pdo);
$migrationsDir = __DIR__ . '/../db/migrations';
$action = $_GET['action'] ?? 'status';

header('Content-Type: application/json');

switch ($action) {
    case 'status':
        echo json_encode($runner->status($migrationsDir), JSON_PRETTY_PRINT);
        break;

    case 'migrate':
        $result = $runner->migrate($migrationsDir, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        echo json_encode($result, JSON_PRETTY_PRINT);

        // Auto-disable on success
        $lastEntry = end($result);
        if (($lastEntry['status'] ?? '') === 'DONE') {
            @rename(__FILE__, __DIR__ . '/../storage/install.php.disabled');
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Use ?action=status or ?action=migrate']);
}

@unlink($lockFile);
```

### Converting Laravel Migrations to SQL

**Source:** `database/migrations/2024_01_15_create_users_table.php`

```php
// Laravel
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->rememberToken();
    $table->timestamps();
});
```

**Target:** `db/migrations/001_create_users.sql`

```sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Laravel Schema → SQL Quick Reference

| Laravel Blueprint | MySQL SQL |
|---|---|
| `$table->id()` | `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `$table->string('x')` | `x VARCHAR(255) NOT NULL` |
| `$table->string('x', 100)` | `x VARCHAR(100) NOT NULL` |
| `$table->text('x')` | `x TEXT NOT NULL` |
| `$table->integer('x')` | `x INT NOT NULL` |
| `$table->bigInteger('x')` | `x BIGINT NOT NULL` |
| `$table->boolean('x')` | `x TINYINT(1) NOT NULL DEFAULT 0` |
| `$table->decimal('x', 10, 2)` | `x DECIMAL(10,2) NOT NULL` |
| `$table->date('x')` | `x DATE NOT NULL` |
| `$table->dateTime('x')` | `x DATETIME NOT NULL` |
| `$table->timestamps()` | `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `$table->softDeletes()` | `deleted_at TIMESTAMP NULL DEFAULT NULL` |
| `->nullable()` | Remove `NOT NULL`, add `NULL` |
| `->default('x')` | `DEFAULT 'x'` |
| `->unique()` | `UNIQUE` or `UNIQUE INDEX` |
| `$table->index(['a', 'b'])` | `INDEX idx_a_b (a, b)` |
| `$table->foreign('x')->references('id')->on('y')` | `FOREIGN KEY (x) REFERENCES y(id)` |
| `->onDelete('cascade')` | `ON DELETE CASCADE` |
| `$table->rememberToken()` | `remember_token VARCHAR(100) NULL` |

---

## 8. Models → Repositories

### Eloquent → PDO Mapping

| Eloquent | PDO Repository |
|---|---|
| `Model::all()` | `$stmt = $pdo->query('SELECT * FROM table'); return $stmt->fetchAll();` |
| `Model::find($id)` | `$stmt = $pdo->prepare('SELECT * FROM table WHERE id = ?'); $stmt->execute([$id]); return $stmt->fetch() ?: null;` |
| `Model::where('x', $v)->get()` | `$stmt = $pdo->prepare('SELECT * FROM table WHERE x = ?'); $stmt->execute([$v]); return $stmt->fetchAll();` |
| `Model::where('x', $v)->first()` | Same as above but `->fetch()` |
| `Model::create([...])` | `$pdo->prepare('INSERT INTO table (a, b) VALUES (?, ?)')->execute([$a, $b]); return $pdo->lastInsertId();` |
| `$model->update([...])` | `$pdo->prepare('UPDATE table SET a = ?, b = ? WHERE id = ?')->execute([$a, $b, $id]);` |
| `$model->delete()` | `$pdo->prepare('DELETE FROM table WHERE id = ?')->execute([$id]);` |
| `Model::with('relation')` | Explicit JOIN or separate query |
| `Model::paginate(15)` | `LIMIT ? OFFSET ?` with count query |
| `Model::where(...)->count()` | `SELECT COUNT(*) FROM table WHERE ...` |

### Repository Template

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use App\Logging\Logger;
use App\Repository\SafeQuery;

class ExampleRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger
    ) {}

    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM examples ORDER BY created_at DESC')->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM examples WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByField(string $field, mixed $value): array
    {
        $col = SafeQuery::column($field, ['status', 'type', 'user_id']);

        $stmt = $this->db->prepare("SELECT * FROM examples WHERE {$col} = ?");
        $stmt->execute([$value]);
        return $stmt->fetchAll();
    }

    public function findByIds(array $ids): array
    {
        if (empty($ids)) return [];

        [$placeholders, $safeIds] = SafeQuery::inClause($ids);
        $stmt = $this->db->prepare("SELECT * FROM examples WHERE id IN ({$placeholders})");
        $stmt->execute($safeIds);
        return $stmt->fetchAll();
    }

    public function search(string $term): array
    {
        $stmt = $this->db->prepare('SELECT * FROM examples WHERE name LIKE ?');
        $stmt->execute(['%' . SafeQuery::escapeLike($term) . '%']);
        return $stmt->fetchAll();
    }

    public function findSorted(string $sortBy, string $direction = 'ASC'): array
    {
        $col = SafeQuery::column($sortBy, ['name', 'created_at']);
        $dir = SafeQuery::direction($direction);

        $stmt = $this->db->prepare("SELECT * FROM examples ORDER BY {$col} {$dir}");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO examples (name, description, user_id, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$data['name'], $data['description'], $data['user_id']]);

        $id = (int) $this->db->lastInsertId();
        $this->logger->info('Example created', ['id' => $id]);
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        [$set, $values] = SafeQuery::buildUpdate($data, ['name', 'description']);
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE examples SET {$set}, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute($values);
        $this->logger->info('Example updated', ['id' => $id]);
        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM examples WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function paginate(int $page = 1, int $perPage = 15): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->query('SELECT COUNT(*) FROM examples');
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare('SELECT * FROM examples ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $stmt->execute([$perPage, $offset]);

        return [
            'data'     => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }
}
```

### Relationship Migration

**Laravel:**

```php
// User hasMany Orders
$user->orders;
$user->orders()->where('status', 'active')->get();
```

**Target:**

```php
// OrderRepository
public function findByUserId(int $userId): array
{
    $stmt = $this->db->prepare('SELECT * FROM orders WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

public function findActiveByUserId(int $userId): array
{
    $stmt = $this->db->prepare('SELECT * FROM orders WHERE user_id = ? AND status = ?');
    $stmt->execute([$userId, 'active']);
    return $stmt->fetchAll();
}

// Eager-load equivalent — single query with JOIN
public function findWithUser(int $orderId): ?array
{
    $stmt = $this->db->prepare('
        SELECT o.*, u.name AS user_name, u.email AS user_email
        FROM orders o
        JOIN users u ON u.id = o.user_id
        WHERE o.id = ?
    ');
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: null;
}
```

---

## 8b. SQL Injection Prevention

Without Eloquent's query builder, you are writing raw SQL. PDO prepared statements protect values, but **identifiers (column names, table names, sort direction) cannot be parameterized**. The `SafeQuery` utility and the patterns below are mandatory for every repository.

### `src/Repository/SafeQuery.php`

```php
<?php

declare(strict_types=1);

namespace App\Repository;

class SafeQuery
{
    /**
     * Validates a column name against a whitelist.
     * Use whenever a column name originates from user input (sort fields, filters).
     */
    public static function column(string $input, array $allowed): string
    {
        if (!in_array($input, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid column: {$input}");
        }
        return $input;
    }

    /**
     * Validates sort direction. Returns only 'ASC' or 'DESC'.
     */
    public static function direction(string $input): string
    {
        return strtoupper($input) === 'DESC' ? 'DESC' : 'ASC';
    }

    /**
     * Validates a table name against a whitelist.
     * Use when table names are dynamic (rare but possible in multi-tenant setups).
     */
    public static function table(string $input, array $allowed): string
    {
        if (!in_array($input, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid table: {$input}");
        }
        return $input;
    }

    /**
     * Generates positional placeholders for IN (...) clauses.
     * Returns the placeholder string and a sanitized values array.
     *
     * Usage:
     *   [$placeholders, $safeIds] = SafeQuery::inClause($ids);
     *   $stmt = $pdo->prepare("SELECT * FROM users WHERE id IN ({$placeholders})");
     *   $stmt->execute($safeIds);
     */
    public static function inClause(array $values, string $type = 'int'): array
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("IN clause cannot be empty");
        }

        $sanitized = match ($type) {
            'int'    => array_map('intval', $values),
            'string' => array_values($values), // bound via prepared statement
            default  => throw new \InvalidArgumentException("Unknown type: {$type}"),
        };

        $placeholders = implode(',', array_fill(0, count($sanitized), '?'));

        return [$placeholders, $sanitized];
    }

    /**
     * Escapes LIKE wildcard characters in a search term.
     * Bind the result as a normal parameter — this only neutralizes %, _, and \.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    /**
     * Builds a safe SET clause from an associative array, filtering against allowed columns.
     * Returns [$setClause, $values] for use in UPDATE statements.
     *
     * Usage:
     *   [$set, $values] = SafeQuery::buildUpdate($data, ['name', 'price', 'status']);
     *   $values[] = $id;
     *   $stmt = $pdo->prepare("UPDATE products SET {$set} WHERE id = ?");
     *   $stmt->execute($values);
     */
    public static function buildUpdate(array $data, array $allowed): array
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $fields[] = "{$key} = ?";
            $values[] = $value;
        }

        if (empty($fields)) {
            throw new \InvalidArgumentException("No valid fields to update");
        }

        return [implode(', ', $fields), $values];
    }
}
```

### Safe Query Patterns — Reference

#### Dynamic Column Names (Sort, Filter)

```php
// VULNERABLE
$stmt = $pdo->prepare("SELECT * FROM products ORDER BY {$_GET['sort']}");

// SAFE
$col = SafeQuery::column($sortBy, ['name', 'created_at', 'price']);
$dir = SafeQuery::direction($direction);
$stmt = $this->db->prepare("SELECT * FROM products ORDER BY {$col} {$dir}");
$stmt->execute();
```

#### IN (...) Clauses

```php
// VULNERABLE
$ids = implode(',', $userIds);
$pdo->query("SELECT * FROM users WHERE id IN ({$ids})");

// SAFE
[$placeholders, $safeIds] = SafeQuery::inClause($userIds);
$stmt = $this->db->prepare("SELECT * FROM users WHERE id IN ({$placeholders})");
$stmt->execute($safeIds);
```

#### Dynamic UPDATE SET

```php
// VULNERABLE
foreach ($data as $key => $value) {
    $sql .= "{$key} = '{$value}',";
}

// SAFE
[$set, $values] = SafeQuery::buildUpdate($data, ['name', 'description', 'price']);
$values[] = $id;
$stmt = $this->db->prepare("UPDATE products SET {$set}, updated_at = NOW() WHERE id = ?");
$stmt->execute($values);
```

#### LIKE Search

```php
// VULNERABLE
$stmt = $pdo->prepare("SELECT * FROM products WHERE name LIKE '%{$search}%'");

// SAFE
$stmt = $this->db->prepare('SELECT * FROM products WHERE name LIKE ?');
$stmt->execute(['%' . SafeQuery::escapeLike($term) . '%']);
```

#### Integer Route Parameters

```php
// Always cast to int — defense in depth even with prepared statements
$id = (int) $args['id'];
$stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
```

### SQL Injection Prevention Rules

These rules are mandatory for every repository and query in the codebase:

1. **NEVER** interpolate variables into SQL strings
2. **ALWAYS** use prepared statements with `?` or `:named` placeholders for values
3. Column and table names **CANNOT** be parameterized — use `SafeQuery::column()` / `SafeQuery::table()` whitelist
4. Sort direction — validate with `SafeQuery::direction()`, never pass raw input
5. `IN (...)` clauses — use `SafeQuery::inClause()` to generate placeholders dynamically
6. `LIKE` queries — use `SafeQuery::escapeLike()` to neutralize `%`, `_`, `\` before binding
7. `UPDATE SET` with dynamic fields — use `SafeQuery::buildUpdate()` with a column whitelist
8. Integer IDs from route params — cast with `(int)` before use, even with prepared statements
9. **NEVER** use `$pdo->query()` or `$pdo->exec()` with any user-supplied data
10. The **only** place raw SQL execution (`$pdo->exec()`) is acceptable is `MigrationRunner`, which reads trusted files from the codebase

---

## 9. Authentication

### `src/Service/AuthService.php`

```php
<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use App\Logging\Logger;

class AuthService
{
    public function __construct(
        private PDO $db,
        private Logger $logger
    ) {}

    public function login(string $email, string $password): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, role, password_hash FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->logger->warning('Login failed', ['email' => $email]);
            return null;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role']    = $user['role'];
        $_SESSION['started'] = time();
        $_SESSION['last_regen'] = time();

        $this->logger->info('Login success', ['user_id' => $user['id']]);

        unset($user['password_hash']);
        return $user;
    }

    public function logout(): void
    {
        $userId = $_SESSION['user_id'] ?? 'unknown';
        session_destroy();
        $this->logger->info('Logout', ['user_id' => $userId]);
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}
```

### `src/Service/ApiTokenService.php`

```php
<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use App\Config\AppConfig;
use App\Logging\Logger;

class ApiTokenService
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
        private AppConfig $config
    ) {}

    public function create(int $userId, string $role = 'read'): string
    {
        $plain = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare(
            'INSERT INTO api_tokens (user_id, token, role, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            hash('sha256', $plain),
            $role,
            date('Y-m-d H:i:s', strtotime("+{$this->config->tokenTtlDays} days")),
        ]);

        $this->logger->info('API token created', ['user_id' => $userId, 'role' => $role]);

        return $plain;
    }

    public function revoke(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM api_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        $count = $stmt->rowCount();

        $this->logger->info('Tokens revoked', ['user_id' => $userId, 'count' => $count]);
        return $count;
    }

    public function purgeExpired(): int
    {
        $stmt = $this->db->prepare('DELETE FROM api_tokens WHERE expires_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
```

### `src/Middleware/SessionAuthMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use App\Config\AppConfig;
use App\Logging\Logger;

class SessionAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Logger $logger,
        private AppConfig $config
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly'  => true,
                'cookie_secure'   => true,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true,
            ]);
        }

        if (empty($_SESSION['user_id'])) {
            return $this->deny('No session');
        }

        if (time() - ($_SESSION['started'] ?? 0) > $this->config->sessionMaxAge) {
            session_destroy();
            return $this->deny('Session expired');
        }

        if (time() - ($_SESSION['last_regen'] ?? 0) > $this->config->sessionRegenInterval) {
            session_regenerate_id(true);
            $_SESSION['last_regen'] = time();
        }

        $request = $request
            ->withAttribute('user_id', $_SESSION['user_id'])
            ->withAttribute('role', $_SESSION['role']);

        return $handler->handle($request);
    }

    private function deny(string $reason): Response
    {
        $this->logger->warning('Session auth denied', ['reason' => $reason]);
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
```

### `src/Middleware/ApiTokenMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use PDO;
use App\Logging\Logger;

class ApiTokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PDO $db,
        private Logger $logger
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^(?:Bearer|Token)\s+(.+)$/i', $header, $m)) {
            $plain = $m[1];
        } else {
            return $this->deny('Missing token');
        }

        $stmt = $this->db->prepare(
            'SELECT user_id, role FROM api_tokens WHERE token = ? AND expires_at > NOW()'
        );
        $stmt->execute([hash('sha256', $plain)]);
        $token = $stmt->fetch();

        if (!$token) {
            $this->logger->warning('Invalid API token', [
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
            ]);
            return $this->deny('Invalid or expired token');
        }

        $request = $request
            ->withAttribute('user_id', $token['user_id'])
            ->withAttribute('role', $token['role']);

        return $handler->handle($request);
    }

    private function deny(string $reason): Response
    {
        $this->logger->warning('API auth denied', ['reason' => $reason]);
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
```

### Laravel Auth Migration Reference

| Laravel | Target |
|---|---|
| `Auth::attempt(['email' => $e, 'password' => $p])` | `$authService->login($e, $p)` |
| `Auth::logout()` | `$authService->logout()` |
| `Auth::user()` | `$request->getAttribute('user_id')` |
| `Auth::id()` | `$request->getAttribute('user_id')` |
| `Auth::check()` | Middleware handles — if you reach the controller, user is authenticated |
| `$request->user()->role` | `$request->getAttribute('role')` |
| `auth('sanctum')` middleware | `ApiTokenMiddleware` on route group |
| `auth('web')` middleware | `SessionAuthMiddleware` on route group |
| `Hash::make($password)` | `password_hash($password, PASSWORD_BCRYPT)` |
| `Hash::check($password, $hash)` | `password_verify($password, $hash)` |

---

## 10. Routes Migration

### `src/Config/routes.php`

```php
<?php

declare(strict_types=1);

use Slim\App;
use App\Config\ServiceFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return function (App $app, ServiceFactory $factory) {

    // --- Public routes ---
    $app->post('/login', function (Request $request, Response $response) use ($factory) {
        $body = json_decode($request->getBody()->getContents(), true);
        $user = $factory->authService()->login($body['email'] ?? '', $body['password'] ?? '');

        if (!$user) {
            $response->getBody()->write(json_encode(['error' => 'Invalid credentials']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($user));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->post('/logout', function (Request $request, Response $response) use ($factory) {
        $factory->authService()->logout();
        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // --- Session-protected routes (browser) ---
    $app->group('/app', function ($group) use ($factory) {
        // Map Laravel web.php routes here
    })->add($factory->sessionAuth());

    // --- Token-protected routes (API) ---
    $app->group('/api/v1', function ($group) use ($factory) {
        // Map Laravel api.php routes here
    })->add($factory->apiTokenAuth());
};
```

### Laravel Route → Slim Route Mapping

| Laravel | Slim |
|---|---|
| `Route::get('/x', [Ctrl::class, 'method'])` | `$app->get('/x', [Ctrl::class, 'method'])` |
| `Route::post(...)` | `$app->post(...)` |
| `Route::put(...)` | `$app->put(...)` |
| `Route::delete(...)` | `$app->delete(...)` |
| `Route::prefix('/api')->group(...)` | `$app->group('/api', function ($group) { ... })` |
| `Route::middleware('auth')->group(...)` | `->add($factory->sessionAuth())` on group |
| `Route::middleware('auth:sanctum')` | `->add($factory->apiTokenAuth())` on group |
| `Route::resource('x', XController::class)` | Expand manually into GET/POST/PUT/DELETE |
| `{id}` in route | `{id}` — same syntax |
| `$request->input('key')` | `$body = json_decode($request->getBody()->getContents(), true); $body['key']` |
| `$request->query('key')` | `$request->getQueryParams()['key']` |
| `$request->route('id')` | `$request->getAttribute('id')` (from Slim route args) |
| `response()->json($data)` | `$response->getBody()->write(json_encode($data)); return $response->withHeader('Content-Type', 'application/json');` |
| `response()->json($data, 201)` | Same as above with `->withStatus(201)` |

### Controller Template

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\ExampleService;

class ExampleController
{
    public function __construct(private ExampleService $service) {}

    public function list(Request $request, Response $response): Response
    {
        $page    = (int) ($request->getQueryParams()['page'] ?? 1);
        $result  = $this->service->paginate($page);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $item = $this->service->findById((int) $args['id']);

        if (!$item) {
            $response->getBody()->write(json_encode(['error' => 'Not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($item));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(Request $request, Response $response): Response
    {
        $body   = json_decode($request->getBody()->getContents(), true);
        $userId = $request->getAttribute('user_id');

        $id = $this->service->create($body, $userId);

        $response->getBody()->write(json_encode(['id' => $id]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $body = json_decode($request->getBody()->getContents(), true);
        $this->service->update((int) $args['id'], $body);

        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->service->delete((int) $args['id']);

        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

---

## 11. Apache Configuration

### `public/.htaccess`

```apache
RewriteEngine On

# Block dotfiles
RedirectMatch 403 /\.(.*)$

# Front controller
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]

# Security headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "DENY"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

---

## 12. `.gitignore`

```gitignore
/vendor/
/.env
/logs/*.log
/storage/install.lock
/storage/install.php.disabled
*.php.disabled
.DS_Store
Thumbs.db
```

---

## 13. Validation

### `src/Validation/Validator.php`

```php
<?php

declare(strict_types=1);

namespace App\Validation;

class Validator
{
    private array $errors = [];

    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $error = $this->check($field, $value, $rule);
                if ($error) {
                    $this->errors[$field][] = $error;
                }
            }
        }

        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function check(string $field, mixed $value, string $rule): ?string
    {
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $param = $parts[1] ?? null;

        return match ($ruleName) {
            'required' => ($value === null || $value === '') ? "{$field} is required" : null,
            'string'   => (!is_string($value) && $value !== null) ? "{$field} must be a string" : null,
            'integer'  => (!is_numeric($value) && $value !== null) ? "{$field} must be an integer" : null,
            'email'    => ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) ? "{$field} must be a valid email" : null,
            'min'      => (is_string($value) && strlen($value) < (int)$param) ? "{$field} must be at least {$param} characters" : null,
            'max'      => (is_string($value) && strlen($value) > (int)$param) ? "{$field} must be at most {$param} characters" : null,
            default    => null,
        };
    }
}
```

### Usage in Controller/Service

```php
$validator = new Validator();

if (!$validator->validate($body, [
    'name'  => ['required', 'string', 'min:2', 'max:255'],
    'email' => ['required', 'email'],
    'price' => ['required', 'integer'],
])) {
    $response->getBody()->write(json_encode(['errors' => $validator->errors()]));
    return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
}
```

### Laravel Validation → Target Mapping

| Laravel | Target |
|---|---|
| `$request->validate([...])` | `$validator->validate($body, [...])` |
| `'required'` | `'required'` |
| `'string'` | `'string'` |
| `'email'` | `'email'` |
| `'min:3'` | `'min:3'` |
| `'max:255'` | `'max:255'` |
| `'unique:users,email'` | Handle in repository with a `findByEmail` check |
| `'exists:table,col'` | Handle in repository with explicit query |
| `'confirmed'` | Manual: `$body['password'] === $body['password_confirmation']` |

---

## 14. Error Handling

### Custom Error Handler in `public/index.php`

```php
$errorMiddleware = $app->addErrorMiddleware($config->debug, true, true);

$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    \Throwable $exception,
    bool $displayErrorDetails
) use ($logger, $config): Response {

    $logger->error('Unhandled exception', [
        'message' => $exception->getMessage(),
        'file'    => $exception->getFile(),
        'line'    => $exception->getLine(),
        'uri'     => (string) $request->getUri(),
    ]);

    $status = 500;
    if ($exception instanceof \Slim\Exception\HttpNotFoundException) {
        $status = 404;
    } elseif ($exception instanceof \Slim\Exception\HttpMethodNotAllowedException) {
        $status = 405;
    }

    $body = ['error' => $status === 500 ? 'Internal server error' : $exception->getMessage()];

    if ($config->debug) {
        $body['detail'] = $exception->getMessage();
        $body['file']   = $exception->getFile() . ':' . $exception->getLine();
        $body['trace']  = explode("\n", $exception->getTraceAsString());
    }

    $response = new \Slim\Psr7\Response();
    $response->getBody()->write(json_encode($body));
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
});
```

### Laravel → Target Exception Mapping

| Laravel | Target |
|---|---|
| `App\Exceptions\Handler` | Slim error middleware (above) |
| `abort(404)` | `throw new \Slim\Exception\HttpNotFoundException($request)` |
| `abort(403)` | `throw new \Slim\Exception\HttpForbiddenException($request)` |
| `abort(422, 'msg')` | Return 422 response directly |
| `ModelNotFoundException` | Return 404 from controller |
| `ValidationException` | Return 422 with `$validator->errors()` |

---

## 15. CORS Middleware

### `src/Middleware/CorsMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $allowedOrigins = ['*'],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-API-Token']
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Preflight
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Slim\Psr7\Response();
            return $this->addHeaders($request, $response)->withStatus(204);
        }

        $response = $handler->handle($request);
        return $this->addHeaders($request, $response);
    }

    private function addHeaders(Request $request, Response $response): Response
    {
        $origin = $request->getHeaderLine('Origin');

        if (in_array('*', $this->allowedOrigins, true)) {
            $allowOrigin = '*';
        } elseif (in_array($origin, $this->allowedOrigins, true)) {
            $allowOrigin = $origin;
        } else {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}
```

---

## 16. Request Logging Middleware

### `src/Middleware/RequestLoggerMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use App\Logging\Logger;

class RequestLoggerMiddleware implements MiddlewareInterface
{
    public function __construct(private Logger $logger) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $start = microtime(true);

        $response = $handler->handle($request);

        $duration = round((microtime(true) - $start) * 1000, 2);

        $this->logger->info('HTTP', [
            'method'   => $request->getMethod(),
            'uri'      => (string) $request->getUri()->getPath(),
            'status'   => $response->getStatusCode(),
            'ms'       => $duration,
            'ip'       => $request->getServerParams()['REMOTE_ADDR'] ?? '-',
            'user_id'  => $request->getAttribute('user_id', '-'),
        ]);

        return $response;
    }
}
```

---

## 17. Service Factory — Complete Wiring

### `src/Config/ServiceFactory.php`

```php
<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use App\Logging\Logger;
use App\Middleware\SessionAuthMiddleware;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RequestLoggerMiddleware;
use App\Middleware\CorsMiddleware;
use App\Service\AuthService;
use App\Service\ApiTokenService;
// Import all controllers, services, repositories here

class ServiceFactory
{
    private array $instances = [];

    public function __construct(
        private PDO $db,
        private Logger $logger,
        private AppConfig $config
    ) {}

    // --- Middleware ---

    public function sessionAuth(): SessionAuthMiddleware
    {
        return $this->singleton(SessionAuthMiddleware::class, fn() =>
            new SessionAuthMiddleware($this->logger, $this->config)
        );
    }

    public function apiTokenAuth(): ApiTokenMiddleware
    {
        return $this->singleton(ApiTokenMiddleware::class, fn() =>
            new ApiTokenMiddleware($this->db, $this->logger)
        );
    }

    public function requestLogger(): RequestLoggerMiddleware
    {
        return $this->singleton(RequestLoggerMiddleware::class, fn() =>
            new RequestLoggerMiddleware($this->logger)
        );
    }

    public function cors(): CorsMiddleware
    {
        return $this->singleton(CorsMiddleware::class, fn() =>
            new CorsMiddleware()
        );
    }

    // --- Auth Services ---

    public function authService(): AuthService
    {
        return $this->singleton(AuthService::class, fn() =>
            new AuthService($this->db, $this->logger)
        );
    }

    public function tokenService(): ApiTokenService
    {
        return $this->singleton(ApiTokenService::class, fn() =>
            new ApiTokenService($this->db, $this->logger, $this->config)
        );
    }

    // --- APP-SPECIFIC: Add repositories, services, controllers below ---
    // Follow this pattern for each domain:
    //
    // public function exampleRepo(): ExampleRepository
    // {
    //     return $this->singleton(ExampleRepository::class, fn() =>
    //         new ExampleRepository($this->db, $this->logger)
    //     );
    // }
    //
    // public function exampleService(): ExampleService
    // {
    //     return $this->singleton(ExampleService::class, fn() =>
    //         new ExampleService($this->exampleRepo(), $this->logger)
    //     );
    // }
    //
    // public function exampleController(): ExampleController
    // {
    //     return $this->singleton(ExampleController::class, fn() =>
    //         new ExampleController($this->exampleService())
    //     );
    // }

    // --- Singleton helper ---

    private function singleton(string $key, callable $factory): mixed
    {
        return $this->instances[$key] ??= $factory();
    }
}
```

---

## 18. Facade / Helper Removal Checklist

Laravel facades and helpers must be replaced with explicit calls. Search the codebase for these patterns:

| Search for | Replace with |
|---|---|
| `use Illuminate\...` | Remove — no Laravel imports |
| `Auth::` | Injected `AuthService` or request attributes |
| `DB::` | Injected `PDO` via repository |
| `Log::` | Injected `Logger` |
| `Cache::` | Remove or implement simple file cache |
| `Session::` | Native `$_SESSION` via middleware |
| `Request::` | PSR-7 `$request` parameter |
| `Response::` | PSR-7 `$response` parameter |
| `Validator::` | `new Validator()` |
| `Hash::` | `password_hash()` / `password_verify()` |
| `Str::` | Native PHP string functions |
| `Carbon::` | Native `DateTime` / `DateTimeImmutable` |
| `config('key')` | `Env::get('KEY')` or `AppConfig` property |
| `env('KEY')` | `Env::get('KEY')` |
| `redirect()` | `$response->withHeader('Location', $url)->withStatus(302)` |
| `abort(...)` | Throw Slim HTTP exception or return response |
| `response()->json(...)` | `$response->getBody()->write(json_encode(...))` |
| `collect(...)` | Native `array_map`, `array_filter`, `array_reduce` |
| `optional(...)` | Null coalescing `?->` operator |
| `now()` | `new DateTimeImmutable()` |
| `bcrypt(...)` | `password_hash(..., PASSWORD_BCRYPT)` |

---

## 19. Laravel Feature → Disposition

| Laravel Feature | Disposition |
|---|---|
| Eloquent ORM | → PDO repositories |
| Blade templates | → Remove (API only) or replace with plain PHP templates |
| Artisan commands | → `install.php` web endpoint |
| Queues/Jobs | → Not supported — execute synchronously or defer to cron |
| Events/Listeners | → Direct method calls in services |
| Notifications | → Direct mail/API calls in services |
| Middleware | → Slim PSR-15 middleware (1:1 mapping) |
| Service Providers | → `ServiceFactory` |
| Form Requests | → `Validator` class in controller |
| Policies/Gates | → Role check in middleware or service |
| Scheduling | → IONOS cron job calling a protected endpoint |
| Broadcasting | → Not supported on shared hosting |
| File Storage | → Native `file_put_contents` / `move_uploaded_file` |
| Mail | → `mail()` function or direct SMTP via sockets |

---

## 20. Deployment to IONOS

### Upload Structure

```
IONOS document root (public/)
├── .htaccess
├── index.php
└── install.php

IONOS home (above document root)
├── .env
├── composer.json
├── composer.lock
├── vendor/
├── src/
├── db/
├── logs/
└── storage/
```

### Deployment Steps

```bash
# 1. Upload all files (FTP/SFTP/rsync)
# 2. If SSH available:
cd /path/to/project
php composer.phar install --no-dev --optimize-autoloader

# 3. Run migrations (SSH or web)
# SSH:
php public/install.php migrate
# Web:
curl -H "X-Install-Key: YOUR_KEY" "https://yourdomain.de/install.php?action=migrate"

# 4. Verify
curl -H "X-Install-Key: YOUR_KEY" "https://yourdomain.de/install.php?action=status"

# 5. Set permissions
chmod 600 .env
chmod 755 logs/
chmod 755 storage/

# 6. Disable installer
rm public/install.php  # or it self-disables after successful migration
```

### Post-Deployment Verification

- [ ] `https://yourdomain.de/` returns expected response
- [ ] `https://yourdomain.de/.env` returns 403
- [ ] `https://yourdomain.de/install.php` returns 403/404 (removed)
- [ ] Login endpoint works
- [ ] API token endpoint works
- [ ] `logs/` directory contains today's log file
- [ ] Error responses hide details in production (`APP_DEBUG=false`)

---

## 21. Agent Instructions

When using this plan to migrate a specific Laravel project:

1. **Scan first:** Read the Laravel project's `composer.json`, `routes/`, `app/Models/`, `app/Http/Controllers/`, `database/migrations/`, and `.env` before writing any code.
2. **Migrate schema first:** Convert all Laravel migrations to SQL. Preserve order. This is the foundation.
3. **One domain at a time:** For each model, create the repository → service → controller → route chain completely before moving to the next.
4. **Test each endpoint** as it's migrated rather than migrating everything then testing.
5. **Preserve business logic exactly.** The goal is a framework swap, not a rewrite of application behavior.
6. **Search for every facade/helper** listed in Section 18. Missing one will cause runtime errors.
7. **Do not add packages** beyond `slim/slim` and `slim/psr7` unless explicitly approved.
8. **Keep the ServiceFactory updated** as new classes are added — it is the single source of truth for wiring.
9. **SQL injection prevention is mandatory.** Every repository must use `SafeQuery` for dynamic identifiers. Follow all 10 rules in Section 8b. Specifically:
   - Import and use `SafeQuery::column()` for any dynamic column name in WHERE, ORDER BY, or GROUP BY
   - Import and use `SafeQuery::direction()` for any user-supplied sort direction
   - Import and use `SafeQuery::inClause()` for any `IN (...)` query with dynamic values
   - Import and use `SafeQuery::escapeLike()` for any LIKE search
   - Import and use `SafeQuery::buildUpdate()` for any UPDATE with dynamic field sets
   - Cast all integer route parameters with `(int)` before passing to repositories
   - Never use `$pdo->query()` or `$pdo->exec()` with user-supplied data
10. **Audit after migration.** Grep the entire codebase for these dangerous patterns and fix any occurrences:
    - String interpolation in SQL: `"SELECT.*\$` or `"UPDATE.*\$` or `"DELETE.*\$`
    - Direct `->query()` calls with variables: `->query(".*\$`
    - Direct `->exec()` calls with variables: `->exec(".*\$`
    - Unparameterized WHERE clauses: any SQL string containing `= '` or `= "` followed by a variable
