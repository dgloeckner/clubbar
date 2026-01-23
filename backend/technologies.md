# Backend – Technology Stack and Architecture

## Technologies

| Layer | Technology | Responsibility |
|-------|------------|----------------|
| **Runtime** | PHP 8.3 | Server environment |
| **Framework** | Laravel 11 | Routing, Middleware, DI, Validation |
| **ORM** | Eloquent | DB mapping, Migrations, Relationships |
| **API Spec** | OpenAPI 3.0 | API documentation, Code generation |
| **Code Gen** | openapi-generator | Controller stubs, Request validation |
| **Auth** | Laravel Sanctum | Token-based authentication |
| **Logging** | Monolog | Structured logging, Audit trail |
| **SEPA Export** | digitick/sepa-xml | pain.008 XML generation |
| **Testing** | PHPUnit + Pest | Unit + Feature tests |
| **DB** | SQLite / MySQL | Persistence |

---

## Architecture Layers

| Layer | Responsibility |
|-------|----------------|
| **OpenAPI Spec** | API contract (Single Source of Truth) |
| **Routes** | URL → Controller mapping |
| **Controllers** | Request/Response handling |
| **Requests** | Validation (generated from OAS) |
| **Services** | Business logic |
| **Repositories** | DB queries (optional, direct Eloquent OK) |
| **Models** | Eloquent entities |
| **Exports** | SEPA XML/CSV generation |

---

## Architecture Diagram

```mermaid
flowchart TB
    subgraph OpenAPI["OpenAPI Spec"]
        YAML[api.yaml]
    end

    subgraph Laravel["Laravel Application"]
        Routes[Routes]
        Controllers[Controllers]
        Requests[FormRequests]
        Services[Services]
        Models[Models]
        DB[(Database)]

        Routes --> Controllers
        Controllers --> Requests
        Requests --> Services
        Services --> Models
        Models --> DB
    end

    subgraph CrossCutting["Cross-Cutting Concerns"]
        Auth[Auth - Sanctum]
        Logging[Logging - Monolog]
        SEPA[SEPA Export]
    end

    YAML -->|"Code-Gen (Stubs)"| Routes
    CrossCutting -.-> Laravel
```

---

## Components in Detail

### 1. OpenAPI → Code Generation

| Artifact | Generated | Manual |
|----------|-----------|--------|
| **Controller Stubs** | ✓ Interface/Signature | Implementation |
| **FormRequests** | ✓ Validation rules | - |
| **Routes** | ✓ | - |
| **DTOs/Resources** | ✓ | - |

```bash
# Generation
openapi-generator-cli generate \
  -i openapi/api.yaml \
  -g php-laravel \
  -o app/Generated/
```

### 2. Eloquent ORM (DB Mapping)

| Model | Table | Relationships |
|-------|-------|---------------|
| `User` | `users` | hasMany(Transaction) |
| `Product` | `products` | hasMany(Transaction) |
| `Transaction` | `transactions` | belongsTo(User, Product) |
| `Settlement` | `settlements` | hasMany(SettlementItem) |
| `SettlementItem` | `settlement_items` | belongsTo(Settlement, User) |
| `Admin` | `admins` | - |

### 3. Authentication (Sanctum)

| Aspect | Implementation |
|--------|----------------|
| **Method** | Token-based (Bearer) |
| **Token Creation** | On login |
| **Token Storage** | `personal_access_tokens` table |
| **Validity** | Configurable (e.g., 24h) |
| **Middleware** | `auth:sanctum` |

### 4. Logging (Monolog)

| Log Type | Channel | Content |
|----------|---------|---------|
| **Application** | `daily` | Errors, Warnings |
| **Audit** | `audit` | Admin actions, Changes |
| **SEPA** | `sepa` | Export operations |

### 5. SEPA Export (digitick/sepa-xml)

| Export | Format | Usage |
|--------|--------|-------|
| **SEPA XML** | pain.008.001.02 | Bank upload |
| **CSV** | Semicolon, UTF-8 BOM | Verification, Backup |

---

## Deployment

| Environment | Setup |
|-------------|-------|
| **Development** | `php artisan serve`, SQLite |
| **Production** | nginx + PHP-FPM, MySQL/SQLite |
| **Docker** | Optional, single container sufficient |

Minimum server requirements: PHP 8.3, Composer, SQLite or MySQL.
