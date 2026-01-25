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
// app/Shared/Services/AuditService.php
<?php

namespace App\Shared\Services;

use App\Models\AuditLog;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Utils\IbanMasker;
use Illuminate\Database\Eloquent\Model;

final readonly class AuditService
{
    /**
     * Log an audit entry for master data changes
     *
     * @param AuditAction $action Type of action (create, update, delete, etc.)
     * @param EntityType $entityType Type of entity affected (member, product, etc.)
     * @param string $entityId Primary key of affected record
     * @param array|null $oldValues Field values before change (null for create)
     * @param array|null $newValues Field values after change (null for delete)
     * @param string|null $adminUserId UUID of admin who performed action (nullable for system/failed actions)
     * @param string|null $ipAddress Client IP address (auto-captured from request if not provided)
     * @param string|null $userAgent Browser/client identifier (auto-captured from request if not provided)
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
        // Auto-capture request context if not provided
        $ipAddress ??= request()->ip();
        $userAgent ??= request()->userAgent();

        // Apply IBAN masking to sensitive fields
        $oldValues = $this->maskSensitiveFields($oldValues);
        $newValues = $this->maskSensitiveFields($newValues);

        // Create audit log entry
        AuditLog::create([
            'admin_user_id' => $adminUserId,
            'action' => $action->value,
            'entity_type' => $entityType->value,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Apply sensitive data masking to field values
     *
     * Per ADR-0013:
     * - IBAN: Masked as "DE89****...****4567" (first 4 + last 4 visible)
     * - Password: Replaced with "[CHANGED]" (never log hash or plaintext)
     * - API Token: Never logged (omitted from payload)
     *
     * @param array|null $values Field values to mask
     * @return array|null Masked values
     */
    private function maskSensitiveFields(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        // Mask IBAN if present
        if (isset($values['iban']) && is_string($values['iban'])) {
            $values['iban'] = IbanMasker::mask($values['iban']);
        }

        // Mask password field
        if (isset($values['password'])) {
            $values['password'] = '[CHANGED]';
        }

        // Remove API tokens (never log)
        unset($values['api_token']);

        return $values;
    }
}
```

### Type-Safe Enums (Pattern 002)

Define audit actions and entity types as enums to prevent string-based errors:

```php
// app/Shared/Enums/AuditAction.php
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

// app/Shared/Enums/EntityType.php
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
// app/Shared/Utils/IbanMasker.php
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

### Audit Log Model

Define the audit_log table model:

```php
// app/Models/AuditLog.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog Model - Append-Only Audit Trail
 *
 * Records all changes to master data for compliance and accountability.
 * Per ADR-0013: Audit entries are never updated or deleted.
 */
class AuditLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_log';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Disable updated_at (append-only)
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'admin_user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Relationship: Audit entry belongs to an admin user
     *
     * @return BelongsTo
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id', 'id');
    }
}
```

### Integration into Service Layer (Pattern 004)

Inject AuditService into services and log changes:

```php
// app/Http/Modules/Members/Services/MembersService.php
<?php

namespace App\Http\Modules\Members\Services;

use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;

final readonly class MembersService extends BaseService
{
    public function __construct(
        private MembersRepository $membersRepository,
        private AuditService $auditService,  // Inject audit service
    ) {
        parent::__construct($membersRepository);
    }

    /**
     * Create a new member with audit logging
     */
    public function createMember(
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone,
        ?string $cardUid,
        SupportedLanguage $language,
    ): MemberAdminDto {
        // Create member
        $member = $this->membersRepository->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'card_uid' => $cardUid,
            'preferred_language' => $language->value,
            'is_active' => true,
        ]);

        // Log creation (old_values=null for create)
        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::MEMBER,
            entityId: $member->id,
            oldValues: null,
            newValues: [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'card_uid' => $cardUid,
                'preferred_language' => $language->value,
                'is_active' => true,
            ],
            adminUserId: $this->getCurrentAdminUserId(),
        );

        // Transform and return
        return $this->transformToAdminDto($member);
    }

    /**
     * Update a member with audit logging
     *
     * Only logs changed fields (captured as before/after pairs)
     */
    public function updateMember(string $memberId, array $updateData): MemberAdminDto
    {
        // Fetch current state (before update)
        $oldMember = $this->membersRepository->findById($memberId);

        // Perform update
        $newMember = $this->membersRepository->updateById($memberId, $updateData);

        // Detect which fields changed
        $changedFields = $this->detectChanges($oldMember, $newMember);

        // Log only changed fields (improves readability)
        if (!empty($changedFields['old'])) {
            $this->auditService->log(
                action: AuditAction::UPDATE,
                entityType: EntityType::MEMBER,
                entityId: $newMember->id,
                oldValues: $changedFields['old'],
                newValues: $changedFields['new'],
                adminUserId: $this->getCurrentAdminUserId(),
            );
        }

        return $this->transformToAdminDto($newMember);
    }

    /**
     * Helper: Detect which fields changed between old and new model
     *
     * @param Model $oldModel State before update
     * @param Model $newModel State after update
     * @return array ['old' => [...changed fields...], 'new' => [...changed fields...]]
     */
    private function detectChanges(Model $oldModel, Model $newModel): array
    {
        $oldData = $oldModel->getAttributes();
        $newData = $newModel->getAttributes();

        $oldValues = [];
        $newValues = [];

        foreach ($newData as $key => $newValue) {
            $oldValue = $oldData[$key] ?? null;

            // Only include changed fields
            if ($oldValue !== $newValue) {
                $oldValues[$key] = $oldValue;
                $newValues[$key] = $newValue;
            }
        }

        return [
            'old' => $oldValues,
            'new' => $newValues,
        ];
    }

    /**
     * Helper: Get current admin user from session
     *
     * Returns null for system actions or failed logins (per Pattern 013)
     */
    private function getCurrentAdminUserId(): ?string
    {
        return request()->user()?->id;
    }
}
```

### Service Provider Registration (Pattern 008)

Register AuditService in the service container:

```php
// app/Providers/AppServiceProvider.php
<?php

namespace App\Providers;

use App\Shared\Services\AuditService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register services in the container
     */
    public function register(): void
    {
        // Register AuditService as singleton (one instance per request)
        $this->app->singleton(
            AuditService::class,
            function ($app) {
                return new AuditService();
            }
        );
    }

    /**
     * Bootstrap services (runs after registration)
     */
    public function boot(): void
    {
        // Additional setup if needed
    }
}
```

---

## Testing Audit Logging

Unit testing is straightforward since AuditService has no external dependencies:

```php
// tests/Unit/Services/AuditServiceTest.php
<?php

use App\Models\AuditLog;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class AuditServiceTest extends TestCase
{
    private AuditService $service;

    protected function setUp(): void
    {
        $this->service = new AuditService();
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

        $entry = AuditLog::where('entity_id', 'member-123')->first();

        $this->assertNotNull($entry);
        $this->assertEquals('create', $entry->action);
        $this->assertEquals('member', $entry->entity_type);
        $this->assertEquals(['first_name' => 'John', 'last_name' => 'Doe'], $entry->new_values);
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

        $entry = AuditLog::where('entity_id', 'member-456')->first();

        // Verify IBAN is masked
        $this->assertStringContainsString('****', $entry->old_values['iban']);
        $this->assertStringNotContainsString('370400440532', $entry->old_values['iban']);
    }
}
```

---

## Integration with Multiple Modules

Audit logging follows the same pattern across all modules:

```php
// Any module (Products, Terminals, Settlements) follows same approach:
final readonly class ProductsService extends BaseService
{
    public function __construct(
        private ProductsRepository $repo,
        private AuditService $auditService,  // Always inject
    ) {}

    public function createProduct(string $name): ProductDto
    {
        $product = $this->repo->create(['name' => $name]);

        // Always log changes
        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::PRODUCT,
            entityId: $product->id,
            newValues: ['name' => $name],
        );

        return $product->toDto();
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
- **Transactions**: Already immutable and self-auditing (ADR-0004)
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
