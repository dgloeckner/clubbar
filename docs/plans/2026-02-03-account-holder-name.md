# Account Holder Name Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add `account_holder_name` field to support divergent SEPA account holders (e.g., parent pays for child).

**Architecture:** Add a nullable VARCHAR(70) column to the `members` table. Plumb it through the full backend stack (migration → model → validation → service → DTO → controller → SEPA export) and admin frontend (form field + API service). The SEPA export uses `account_holder_name ?? (first_name . ' ' . last_name)` as the debtor name.

**Tech Stack:** PHP/Laravel (backend), React/TypeScript (admin frontend), MariaDB (database)

---

### Task 1: Database Migration

**Files:**
- Create: `backend/database/migrations/2026_02_03_000000_add_account_holder_name_to_members.php`

**Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('account_holder_name', 70)->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('account_holder_name');
        });
    }
};
```

**Step 2: Run the migration**

```bash
docker compose exec backend php artisan migrate
```

Expected: Migration completes successfully, `account_holder_name` column added after `iban`.

**Step 3: Verify the column exists**

```bash
docker compose exec backend php artisan tinker --execute="echo Schema::hasColumn('members', 'account_holder_name') ? 'YES' : 'NO';"
```

Expected: `YES`

**Step 4: Commit**

```bash
git add backend/database/migrations/2026_02_03_000000_add_account_holder_name_to_members.php
git commit -m "feat: add account_holder_name column to members table

Supports divergent SEPA account holders (e.g., parent pays for child).
Nullable VARCHAR(70) per SEPA maximum name length."
```

---

### Task 2: Backend Model + Validation

**Files:**
- Modify: `backend/app/Models/Member.php` (add to `$fillable`)
- Modify: `backend/app/Http/Modules/Members/Requests/CreateMemberRequest.php` (add validation rule + typed accessor)
- Modify: `backend/app/Http/Modules/Members/Requests/UpdateMemberRequest.php` (add validation rule + typed accessor)

**Step 1: Add `account_holder_name` to Member model `$fillable` array**

In `backend/app/Models/Member.php`, add `'account_holder_name'` to the `$fillable` array after `'iban'`.

**Step 2: Add validation rule and accessor to `CreateMemberRequest`**

In `backend/app/Http/Modules/Members/Requests/CreateMemberRequest.php`:

Add validation rule after the `iban` rule:
```php
'account_holder_name' => ['nullable', 'string', 'max:70'],
```

Add typed accessor after the `iban()` method:
```php
/**
 * Get typed account holder name (nullable)
 *
 * @return string|null
 */
public function accountHolderName(): ?string
{
    return $this->validated('account_holder_name');
}
```

**Step 3: Add validation rule and accessor to `UpdateMemberRequest`**

In `backend/app/Http/Modules/Members/Requests/UpdateMemberRequest.php`:

Add validation rule after the `iban` rule:
```php
'account_holder_name' => ['nullable', 'string', 'max:70'],
```

Add typed accessor after the `iban()` method:
```php
/**
 * Get typed account holder name (nullable)
 *
 * @return string|null
 */
public function accountHolderName(): ?string
{
    return $this->validated('account_holder_name');
}
```

**Step 4: Commit**

```bash
git add backend/app/Models/Member.php \
      backend/app/Http/Modules/Members/Requests/CreateMemberRequest.php \
      backend/app/Http/Modules/Members/Requests/UpdateMemberRequest.php
git commit -m "feat: add account_holder_name to Member model and validation requests"
```

---

### Task 3: Backend DTO + Service + Controller

**Files:**
- Modify: `backend/app/Http/Modules/Members/DTOs/MemberAdminDto.php` (add field + serialization)
- Modify: `backend/app/Http/Modules/Members/Services/MembersService.php` (pass field through all DTO construction sites + create/update methods)
- Modify: `backend/app/Http/Modules/Members/Controllers/AdminController.php` (pass field from request to service)

**Step 1: Add `accountHolderName` to `MemberAdminDto`**

In `backend/app/Http/Modules/Members/DTOs/MemberAdminDto.php`:

Add constructor parameter after `$iban`:
```php
public ?string $accountHolderName,
```

Add to `toArray()` after `'iban'`:
```php
'account_holder_name' => $this->accountHolderName,
```

**Step 2: Update `MembersService` — all places that construct `MemberAdminDto`**

There are 6 places in `MembersService.php` that construct `MemberAdminDto`. Each needs `accountHolderName: $model->account_holder_name,` (or `$member->account_holder_name`) added after the `iban:` parameter.

Locations:
- `listMembers()` (line ~163, inside the `->map()`)
- `getMember()` (line ~207)
- `createMember()` (line ~287)
- `updateMember()` (line ~376)
- `anonymizeMember()` — two locations: line ~497 (set to null for anonymized) and earlier

Also update `createMember()` method signature to accept `?string $accountHolderName = null` parameter and include it in the `create()` data array and audit log.

Also update `updateMember()` to handle the `accountHolderName` key in `$updateData` mapping (add `if (isset($updateData['accountHolderName']))` block).

**Step 3: Update `AdminController` — `store()` and `update()` methods**

In `store()`: Extract `$accountHolderName = $request->accountHolderName();` and pass to `createMember()`.

In `update()`: Add `'accountHolderName' => $request->accountHolderName(),` to the `$updateData` array.

**Step 4: Restart PHP and verify endpoint**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
curl -s http://localhost:8080/api/admin/members?limit=1 | python3 -m json.tool | grep account_holder
```

Expected: `"account_holder_name": null` appears in the response.

**Step 5: Commit**

```bash
git add backend/app/Http/Modules/Members/DTOs/MemberAdminDto.php \
      backend/app/Http/Modules/Members/Services/MembersService.php \
      backend/app/Http/Modules/Members/Controllers/AdminController.php
git commit -m "feat: plumb account_holder_name through DTO, service, and controller"
```

---

### Task 4: SEPA Export — Use Effective Account Holder Name

**Files:**
- Modify: `backend/app/Http/Modules/Settlements/Services/SepaExportService.php` (line ~150)

**Step 1: Update debtor name derivation in `addTransactionsToDocument()`**

In `SepaExportService.php`, change line 150 from:
```php
$memberName = $this->sanitizeName($member->first_name . ' ' . $member->last_name);
```
to:
```php
$memberName = $this->sanitizeName(
    $member->account_holder_name ?? ($member->first_name . ' ' . $member->last_name)
);
```

**Step 2: Restart PHP**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
```

**Step 3: Commit**

```bash
git add backend/app/Http/Modules/Settlements/Services/SepaExportService.php
git commit -m "feat: use account_holder_name as SEPA debtor name when present

Falls back to member first_name + last_name if account_holder_name is NULL."
```

---

### Task 5: GDPR Anonymization — Clear Account Holder Name

**Files:**
- Modify: `backend/app/Http/Modules/Members/Repositories/MembersRepository.php` (add to anonymize method)

**Step 1: Add `account_holder_name` to the anonymize update array**

In `MembersRepository.php`, add `'account_holder_name' => null,` to the `update()` array in the `anonymize()` method, after the `'iban' => null` line.

**Step 2: Commit**

```bash
git add backend/app/Http/Modules/Members/Repositories/MembersRepository.php
git commit -m "feat: clear account_holder_name during GDPR anonymization"
```

---

### Task 6: OpenAPI Spec + ERM Documentation

**Files:**
- Modify: `api/admin.yaml` (add field to Member, MemberCreateRequest, MemberUpdateRequest schemas)
- Modify: `docs/erm-master.md` (add column to members table)

**Step 1: Update OpenAPI spec**

In `api/admin.yaml`, add `account_holder_name` field to these schemas:

**`Member` schema** (after `iban`, around line 2583):
```yaml
        account_holder_name:
          type: string
          nullable: true
          maxLength: 70
          description: Account holder name if different from member. Used in SEPA XML.
```

**`MemberCreateRequest` schema** (after `iban`, around line 2631):
```yaml
        account_holder_name:
          type: string
          maxLength: 70
          description: Account holder name if different from member (for divergent payer)
```

**`MemberUpdateRequest` schema** (after `iban`, around line 2658):
```yaml
        account_holder_name:
          type: string
          maxLength: 70
          nullable: true
```

**Step 2: Update ERM documentation**

In `docs/erm-master.md`, add a row to the `members` table after the `iban` row (around line 183):

```
| account_holder_name | VARCHAR(70) | NULL | Account holder name if different from member (SEPA max 70) |
```

**Step 3: Commit**

```bash
git add api/admin.yaml docs/erm-master.md
git commit -m "docs: add account_holder_name to OpenAPI spec and ERM documentation"
```

---

### Task 7: Admin Frontend — API Service + Form

**Files:**
- Modify: `admin-frontend/src/services/members.ts` (add field to `Member` interface and `createMember` data type)
- Modify: `admin-frontend/src/pages/MembersPage.tsx` (add form field, update formData state, update handleEdit)

**Step 1: Update `Member` interface in `members.ts`**

Add `account_holder_name?: string` after the `iban` field.

Also add `account_holder_name?: string` to the `createMember` data parameter type.

**Step 2: Update `MembersPage.tsx` — form state**

Add `account_holder_name: ''` to the initial `formData` state (line ~52) and all places where `setFormData` resets the form (lines ~127, ~343).

**Step 3: Update `handleEdit` to populate `account_holder_name`**

In `handleEdit()`, add `account_holder_name: member.account_holder_name || '',` to the `setFormData` call.

**Step 4: Add the form field in the modal — after IBAN, before Mandate Reference**

Insert a new form field block after the IBAN `<div>` (after line ~677) and before the Mandate Reference `<div>`:

```tsx
<div>
  <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
    Account Holder Name
  </label>
  <input
    data-testid="members-form-account-holder-name-input"
    type="text"
    value={formData.account_holder_name}
    onChange={(e) => setFormData({ ...formData, account_holder_name: e.target.value })}
    placeholder="Only if different from member"
    maxLength={70}
    style={{
      width: '100%',
      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
      background: theme.colors.bg.input,
      border: `1px solid ${theme.colors.border.light}`,
      borderRadius: theme.borderRadius.md,
      color: theme.colors.text.primary,
      boxSizing: 'border-box',
    }}
  />
  <span style={{ fontSize: '12px', color: theme.colors.text.secondary, marginTop: '4px', display: 'block' }}>
    Only fill if the account holder differs from the member (e.g., parent pays for child)
  </span>
</div>
```

**Step 5: Verify frontend builds**

```bash
cd admin-frontend && npm run build
```

Expected: Build succeeds with no errors.

**Step 6: Commit**

```bash
git add admin-frontend/src/services/members.ts admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat: add account holder name field to admin member form

Placed below IBAN with helper text explaining divergent payer scenario."
```

---

### Task 8: E2E Tests

**Files:**
- Modify: `e2etests/tests/api/admin-members-crud.spec.ts` (add test for creating member with account_holder_name)

**Step 1: Add test for creating member with account_holder_name**

Add a test case that:
1. Creates a member with `account_holder_name` set to a value different from the member name
2. Verifies the response includes `account_holder_name`
3. Fetches the member by ID and verifies `account_holder_name` is persisted

**Step 2: Add test for updating account_holder_name**

Add a test case that:
1. Creates a member without `account_holder_name`
2. Updates the member with `account_holder_name`
3. Verifies the response includes the updated `account_holder_name`

**Step 3: Run the tests**

```bash
cd e2etests && npm test -- --grep "account_holder" --workers=4
```

Expected: All new tests pass.

**Step 4: Run the full member test suite to ensure no regressions**

```bash
cd e2etests && npm test -- tests/api/admin-members-crud.spec.ts --workers=4
```

Expected: All tests pass.

**Step 5: Commit**

```bash
cd e2etests
git add tests/api/admin-members-crud.spec.ts
git commit -m "test: add E2E tests for account_holder_name CRUD operations"
```
