# Pattern 016: Audit Logging for Compliance & Transparency

**Category**: Cross-Cutting Concern & Compliance
**Pattern Type**: Behavioral Pattern
**Related ADR**: ADR-0013 (Audit Logging for Master Data Changes)

---

## Problem

Without audit logging, the system has no accountability trail for administrative actions:

```php
// ❌ Problematic: No record of who changed what
public function updateMember(string $memberId, array $data): MemberDto
{
    $member = $this->membersRepository->updateById($memberId, $data);
    return new MemberAdminDto(...);
}

// Later: Admin questions whether IBAN was correctly updated
// Organization: We cannot prove who changed it or when
// GDPR: We cannot demonstrate lawful processing (Art. 30 accountability)
// Auditor: No evidence of controls over sensitive data changes
```

Issues:
- No accountability for who changed member data
- Cannot reconstruct historical state for dispute resolution
- GDPR Art. 30 compliance violations (Records of Processing Activities)
- No detection of unauthorized access or suspicious patterns
- No evidence for financial reconciliation

---

## Solution

Implement **centralized audit logging** that:
- Records every change to master data with who, what, when, where context
- Captures before/after values (old_values and new_values) for complete transparency
- Masks sensitive fields (IBAN, passwords) per compliance requirements
- Integrates into service layer (before/after each operation)
- Provides query interface for audit log viewer
- Supports filtering and historical reconstruction

---

## Implementation Pattern

### Core Infrastructure: AuditService

Audit logging is a **cross-cutting concern** (like logging or caching) that applies across multiple modules. Create a shared service that all modules depend on.

```php
// src/Shared/Services/AuditService.php
<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\AuditLog\Repositories\AuditLogRepository;

/**
 * Centralized audit logging service.
 *
 * Uses AuditLogRepository (PDO) to insert audit entries.
 * Auto-captures IP address and user agent from $_SERVER superglobals.
 * Masks sensitive fields (IBAN, passwords, API tokens).
 */
class AuditService
{
    public function __construct(
        private AuditLogRepository $auditLogRepository,
    ) {}

    /**
     * Log an audit entry for master data changes
     *
     * @param AuditAction $action Type of action (create, update, delete, etc.)
     * @param EntityType $entityType Type of entity affected (member, product, etc.)
     * @param string $entityId Primary key of affected record
     * @param array|null $oldValues Field values before change (null for create)
     * @param array|null $newValues Field values after change (null for delete)
     * @param string|null $adminUserId UUID of admin who performed action (nullable for system/failed actions)
     * @param string|null $ipAddress Client IP address (auto-captured from $_SERVER if not provided)
     * @param string|null $userAgent Browser/client identifier (auto-captured from $_SERVER if not provided)
     * @return void
     */
    public function log(
        AuditAction $action,
        EntityType $entityType,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $adminUserId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        // Insert via PDO repository
        $this->auditLogRepository->insert([
            'admin_user_id' => $adminUserId,
            'action' => $action->value,
            'entity_type' => $entityType->value,
            'entity_id' => $entityId,
            'old_values' => $this->maskSensitiveFields($oldValues),
            'new_values' => $this->maskSensitiveFields($newValues),
            'ip_address' => $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
        ]);
    }

    /**
     * Apply sensitive data masking to field values
     *
     * Per ADR-0013:
     * - IBAN: Masked as "DE89****...****4567" (first 4 + last 4 visible)
     * - Password: Replaced with "[MASKED]" (never log hash or plaintext)
     * - API Token: Replaced with "[MASKED]" (never log)
     *
     * @param array|null $values Field values to mask
     * @return array|null Masked values
     */
    private function maskSensitiveFields(?array $values): ?array
    {
        if ($values === null) return null;

        $sensitive = ['password', 'api_token', 'api_token_hash'];
        foreach ($sensitive as $field) {
            if (isset($values[$field])) {
                $values[$field] = '[MASKED]';
            }
        }

        if (isset($values['iban']) && $values['iban'] !== '[MASKED]') {
            $values['iban'] = \App\Shared\Utils\IbanMasker::mask($values['iban']);
        }

        return $values;
    }
}
```

### Type-Safe Enums (Pattern 002)

Define audit actions and entity types as enums to prevent string-based errors:

```php
// src/Shared/Enums/AuditAction.php
<?php

namespace App\Shared\Enums;

enum AuditAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case ANONYMIZE = 'anonymize';
    case LOGIN = 'login';
    case LOGOUT = 'logout';
    case LOGIN_FAILED = 'login_failed';
    case EXPORT = 'export';
    case SETTLEMENT_CREATE = 'settlement_create';
    case SETTLEMENT_CANCEL = 'settlement_cancel';
    case SETTLEMENT_EXPORT = 'settlement_export';
}

// src/Shared/Enums/EntityType.php
<?php

namespace App\Shared\Enums;

enum EntityType: string
{
    case MEMBER = 'member';
    case PRODUCT = 'product';
    case ADMIN_USER = 'admin_user';
    case TERMINAL = 'terminal';
    case SETTLEMENT = 'settlement';
    case SEPA_CONFIG = 'sepa_config';
}
```

### IBAN Masking Utility

Provide reusable utility for IBAN masking:

```php
// src/Shared/Utils/IbanMasker.php
<?php

namespace App\Shared\Utils;

final class IbanMasker
{
    /**
     * Mask IBAN for audit log display
     *
     * Per ADR-0013 sensitive data handling:
     * Returns first 4 + last 4 characters with masked middle section
     * Example: DE89370400440532013000 → DE89****...****3000
     *
     * @param string $iban Full IBAN
     * @return string Masked IBAN
     */
    public static function mask(string $iban): string
    {
        // If IBAN too short to mask safely, fully mask it
        if (strlen($iban) < 8) {
            return '****';
        }

        // Return: first 4 chars + masked section + last 4 chars
        $first = substr($iban, 0, 4);
        $last = substr($iban, -4);

        return "{$first}****...****{$last}";
    }
}
```

### Audit Log Repository (PDO)

The audit_log table is accessed via a PDO repository. No ORM model is needed -- the repository handles INSERT and SELECT with raw SQL and prepared statements.

```php
// src/Modules/AuditLog/Repositories/AuditLogRepository.php
<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Repositories;

use PDO;

/**
 * AuditLog Repository - Append-Only Audit Trail (PDO)
 *
 * Records all changes to master data for compliance and accountability.
 * Per ADR-0013: Audit entries are never updated or deleted.
 *
 * old_values and new_values are stored as JSON strings in the database.
 */
class AuditLogRepository
{
    public function __construct(private PDO $db) {}

    /**
     * Insert an audit log entry.
     *
     * @param array $data Audit entry data (admin_user_id, action, entity_type, etc.)
     */
    public function insert(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_log (admin_user_id, action, entity_type, entity_id,
             old_values, new_values, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $data['admin_user_id'],
            $data['action'],
            $data['entity_type'],
            $data['entity_id'],
            $data['old_values'] !== null ? json_encode($data['old_values']) : null,
            $data['new_values'] !== null ? json_encode($data['new_values']) : null,
            $data['ip_address'],
            $data['user_agent'],
        ]);
    }

    /**
     * Find audit log entries with filtering and pagination.
     *
     * Joins admin_users table to include admin name in results.
     *
     * @param array $filters Optional filters (entity_type, action, admin_user_id, etc.)
     * @param int $limit
     * @param int $offset
     * @return array List of audit log rows with admin user name
     */
    public function findAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT al.*, au.name as admin_name
                FROM audit_log al
                LEFT JOIN admin_users au ON al.admin_user_id = au.id';

        // Add WHERE clauses based on filters...
        $sql .= ' ORDER BY al.created_at DESC LIMIT ? OFFSET ?';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }
}
```

### Integration into Service Layer (Pattern 004)

Inject AuditService into services and log changes:

```php
// src/Modules/Members/Services/MembersService.php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;

class MembersService
{
    public function __construct(
        private MembersRepository $membersRepository,
        private AuditService $auditService,  // Inject audit service
    ) {}

    /**
     * Create a new member with audit logging
     */
    public function createMember(array $data, ?string $adminUserId = null): array
    {
        // Create member via PDO repository
        $memberId = $this->membersRepository->create($data);
        $member = $this->membersRepository->findById($memberId);

        // Log creation (old_values=null for create)
        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::MEMBER,
            entityId: $memberId,
            oldValues: null,
            newValues: $data,
            adminUserId: $adminUserId,
        );

        return $member;
    }

    /**
     * Update a member with audit logging
     *
     * Only logs changed fields (captured as before/after pairs)
     */
    public function updateMember(string $memberId, array $updateData, ?string $adminUserId = null): array
    {
        // Fetch current state (before update) via PDO
        $oldMember = $this->membersRepository->findById($memberId);

        // Perform update via PDO repository
        $this->membersRepository->updateById($memberId, $updateData);
        $newMember = $this->membersRepository->findById($memberId);

        // Detect which fields changed
        $changedFields = $this->detectChanges($oldMember, $newMember);

        // Log only changed fields (improves readability)
        if (!empty($changedFields['old'])) {
            $this->auditService->log(
                action: AuditAction::UPDATE,
                entityType: EntityType::MEMBER,
                entityId: $memberId,
                oldValues: $changedFields['old'],
                newValues: $changedFields['new'],
                adminUserId: $adminUserId,
            );
        }

        return $newMember;
    }

    /**
     * Helper: Detect which fields changed between old and new row
     *
     * @param array $oldRow Associative array before update (from PDO)
     * @param array $newRow Associative array after update (from PDO)
     * @return array ['old' => [...changed fields...], 'new' => [...changed fields...]]
     */
    private function detectChanges(array $oldRow, array $newRow): array
    {
        $oldValues = [];
        $newValues = [];

        foreach ($newRow as $key => $newValue) {
            $oldValue = $oldRow[$key] ?? null;

            // Only include changed fields (skip timestamps)
            if ($oldValue !== $newValue && !in_array($key, ['updated_at'])) {
                $oldValues[$key] = $oldValue;
                $newValues[$key] = $newValue;
            }
        }

        return [
            'old' => $oldValues,
            'new' => $newValues,
        ];
    }
}
```

### ServiceFactory Registration (Pattern 008)

Register AuditService in the custom ServiceFactory (PSR ContainerInterface):

```php
// src/ServiceFactory.php
<?php

declare(strict_types=1);

namespace App;

use App\Shared\Services\AuditService;
use App\Modules\AuditLog\Repositories\AuditLogRepository;

class ServiceFactory implements \Psr\Container\ContainerInterface
{
    // ...

    private function createAuditService(): AuditService
    {
        return new AuditService(
            $this->get(AuditLogRepository::class),
        );
    }

    private function createAuditLogRepository(): AuditLogRepository
    {
        return new AuditLogRepository($this->getPdo());
    }
}
```

The ServiceFactory wires all dependencies manually (no auto-injection). Each service and repository is created via a factory method, with PDO injected from the shared database connection.

---

## Testing Audit Logging

Unit testing uses PDO with an in-memory SQLite database or a test MariaDB instance:

```php
// tests/Unit/Services/AuditServiceTest.php
<?php

use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use PHPUnit\Framework\TestCase;

class AuditServiceTest extends TestCase
{
    private AuditService $service;
    private AuditLogRepository $repository;
    private PDO $pdo;

    protected function setUp(): void
    {
        // Use test PDO (in-memory or test database)
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_user_id TEXT, action TEXT, entity_type TEXT, entity_id TEXT,
            old_values TEXT, new_values TEXT, ip_address TEXT, user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $this->repository = new AuditLogRepository($this->pdo);
        $this->service = new AuditService($this->repository);
    }

    public function test_log_creates_audit_entry(): void
    {
        $this->service->log(
            action: AuditAction::CREATE,
            entityType: EntityType::MEMBER,
            entityId: 'member-123',
            oldValues: null,
            newValues: ['first_name' => 'John', 'last_name' => 'Doe'],
        );

        $stmt = $this->pdo->prepare('SELECT * FROM audit_log WHERE entity_id = ?');
        $stmt->execute(['member-123']);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($entry);
        $this->assertEquals('create', $entry['action']);
        $this->assertEquals('member', $entry['entity_type']);
        $newValues = json_decode($entry['new_values'], true);
        $this->assertEquals('John', $newValues['first_name']);
    }

    public function test_log_masks_iban(): void
    {
        $this->service->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::MEMBER,
            entityId: 'member-456',
            oldValues: ['iban' => 'DE89370400440532013000'],
            newValues: ['iban' => 'FR1420041010050500013M02606'],
        );

        $stmt = $this->pdo->prepare('SELECT * FROM audit_log WHERE entity_id = ?');
        $stmt->execute(['member-456']);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        $oldValues = json_decode($entry['old_values'], true);

        // Verify IBAN is masked
        $this->assertStringContainsString('****', $oldValues['iban']);
        $this->assertStringNotContainsString('370400440532', $oldValues['iban']);
    }
}
```

---

## Integration with Multiple Modules

Audit logging follows the same pattern across all modules:

```php
// Any module (Products, Terminals, Settlements) follows same approach:
// src/Modules/Products/Services/ProductsService.php

class ProductsService
{
    public function __construct(
        private ProductsRepository $repo,
        private AuditService $auditService,  // Always inject
    ) {}

    public function createProduct(array $data, ?string $adminUserId = null): array
    {
        $productId = $this->repo->create($data);
        $product = $this->repo->findById($productId);

        // Always log changes via PDO-backed AuditService
        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::PRODUCT,
            entityId: $productId,
            newValues: $data,
            adminUserId: $adminUserId,
        );

        return $product;
    }
}
```

---

## Benefits

✅ **GDPR Compliance**: Demonstrates lawful processing (Art. 30 Records)
✅ **Accountability**: Every admin action is traceable with context (who, what, when, where)
✅ **Security**: Detect suspicious patterns, unauthorized access attempts
✅ **Auditability**: Full historical reconstruction for disputes or investigations
✅ **Non-repudiation**: Admin cannot deny actions; timestamp + IP + user agent captured
✅ **Sensitive Data Protection**: IBAN, passwords masked per compliance requirements
✅ **Immutability**: Append-only design prevents tampering
✅ **Reusability**: One service, integrated across all modules

---

## When to Use

- **All master data changes**: Create, update, delete, anonymize operations
- **Sensitive operations**: SEPA config, admin user management, settlements
- **Compliance requirements**: GDPR, financial audits, regulatory reporting
- **Access tracking**: Login, logout, failed attempts

---

## When NOT to Use

- **Read-only operations**: GET requests (no change, nothing to log)
- **Transactions**: Already immutable and self-auditing (ADR-0004). Two exceptions, both recording what the ledger cannot say about itself: `transaction_storno` (who reversed a booking, and why) and `transaction_price_divergence` (a synced amount disagreed with the catalogue at the moment it was stored). An ordinary purchase still gets no entry
- **Transient data**: Session data, temporary cache (not master data)

---

## Related Patterns

- **Pattern 002**: Enum for Type Safety (AuditAction, EntityType enums)
- **Pattern 003**: Data Transfer Objects (AuditLogDto for API responses)
- **Pattern 004**: Service Layer (AuditService as cross-cutting concern)
- **Pattern 008**: Service Provider Bindings (DI registration)

---

## Related ADRs

- **ADR-0013**: Audit Logging for Master Data Changes (complete specification)
- **ADR-0004**: Immutable Transaction Storage (transactions excluded from audit log)

---

## References

- **GDPR Article 30**: Records of processing activities
- **GDPR Article 5**: Principles of data processing (accountability)
- **§ 147 AO (German Tax Code)**: 10-year retention for business records
- **OWASP Logging Cheat Sheet**: [Logging Guidelines](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)
