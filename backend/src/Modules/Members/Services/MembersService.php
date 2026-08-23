<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\Members\DTOs\MemberDto;
use App\Modules\Members\DTOs\MemberAdminDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\DTOs\SyncResultDto;
use App\Shared\Sync\SyncCursor;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Services\AuditService;
use PDO;

class MembersService
{
    public function __construct(
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private AuditService $auditService,
        private AuditLogRepository $auditLogRepository,
        private NotificationsService $notificationsService,
        private PDO $db,
        private ?BankCodeService $bankCodeService = null,
    ) {}

    /**
     * An IBAN write is the last moment the plaintext exists (ADR-0036), so the
     * bank name is resolved from the BLZ here — a sealed row can never answer
     * that question again. The sealing itself (and the refusal to write
     * without an operational key) lives in the repository.
     */
    private function enrichIbanWrite(array $data): array
    {
        if (($data['iban'] ?? null) === null || $data['iban'] === '') {
            return $data;
        }

        $data['bank_name'] = $this->resolveBankName($data['iban']);

        return $data;
    }

    public function syncSince(int $since): SyncResultDto
    {
        // Taken before the query, not after: it decides whether the newest
        // second the query saw was already complete when the query ran (#84).
        $queriedAt = time();
        $rows = $this->membersRepository->findModifiedSince($since);
        $members = array_map(fn($row) => MemberDto::fromRow($row), $rows);

        return new SyncResultDto(
            items: $members,
            cursor: SyncCursor::next($rows, $since, $queriedAt),
        );
    }

    public function updateLanguage(string $memberId, SupportedLanguage $language): MemberDto
    {
        $member = $this->membersRepository->updateById($memberId, [
            'preferred_language' => $language->value,
        ]);

        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        return MemberDto::fromRow($member);
    }

    public function listMembers(int $limit, int $offset, array $filters = [], string $sortKey = 'created_at', string $sortOrder = 'desc', ?string $search = null): PaginatedResultDto
    {
        $result = $this->membersRepository->listPaginated($limit, $offset, $filters, $sortKey, $sortOrder, $search);

        // Bank names are stored on the mandate at write time (ADR-0036) —
        // there is no plaintext IBAN left to derive them from on read.
        //
        // The birth date is dropped here (ADR-0045): the list is a roster an
        // admin scrolls, and it needs names, balances and SEPA state, not a
        // date of birth on every row. The two consumers that do need it read
        // one member at a time — the edit form loads the member by id before it
        // opens, and the terminal gets it through the sync — so nothing is lost
        // by keeping it off the list. `MemberListItem` in `api/admin.yaml`
        // agrees.
        //
        // What replaces it is the *flag* (#629). A member with no birth date is
        // refused every age-restricted product by the terminal, and until now
        // there was no way to find them from the panel at all — the one gap in
        // a member's data with a legal edge was the one gap invisible from the
        // roster. `has_date_of_birth` says whether the date exists without
        // putting the date itself on a list an admin scrolls, which is what
        // ADR-0045 was protecting.
        $items = array_map(
            function ($row): array {
                $member = MemberAdminDto::fromRow($row)->toArray();
                $member['has_date_of_birth'] = $member['date_of_birth'] !== null;
                unset($member['date_of_birth']);

                return $member;
            },
            $result['items'],
        );

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    /**
     * The Datenqualität panel's figures (#629): how many active members are
     * missing each piece of mandatory data.
     *
     * A dedicated read rather than four filtered list calls with `per_page=1`,
     * so the panel is one request and every count comes from the same snapshot
     * — four probes can disagree with each other if a member is edited between
     * them, and a panel whose numbers do not add up is worse than none.
     *
     * @return array{total:int, without_card_uid:int, without_email:int, without_date_of_birth:int, without_mandate:int, incomplete:int}
     */
    public function getDataCompleteness(): array
    {
        return $this->membersRepository->countDataGaps();
    }

    public function getMember(string $memberId): MemberAdminDto
    {
        $member = $this->membersRepository->findByIdIncludingDeleted($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        // Anonymized members are accessible (deleted_at set, PII fields NULL, card_uid starts with ANON-).
        // Non-anonymized soft-deleted members are not accessible via admin API.
        $isAnonymized = $member['deleted_at'] !== null && str_starts_with($member['card_uid'] ?? '', 'ANON-');
        if ($member['deleted_at'] !== null && !$isAnonymized) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        return MemberAdminDto::fromRow($member);
    }

    public function exportMember(string $memberId): array
    {
        $row = $this->membersRepository->findByIdIncludingDeleted($memberId);
        if (!$row) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        $member = MemberAdminDto::fromRow($row);
        $transactions = $this->transactionsRepository->findByMemberId($memberId, limit: 1000);

        return [
            'member' => $member->toArray(),
            'transactions' => $transactions,
            'bookings' => [], // Future: Settlement bookings will be added here
            'export_timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function createMember(
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone,
        ?string $cardUid,
        SupportedLanguage $language,
        ?string $iban = null,
        ?string $accountHolderName = null,
        ?string $mandateReference = null,
        ?string $mandateSignedAt = null,
        ?string $dateOfBirth = null,
        ?int $creditLimitCents = null,
        ?string $adminUserId = null,
    ): MemberAdminDto {
        $memberData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'date_of_birth' => $dateOfBirth,
            'card_uid' => $cardUid,
            'preferred_language' => $language->value,
            'is_active' => true,
            'iban' => $iban,
            'account_holder_name' => $accountHolderName,
            'mandate_signed_at' => $mandateSignedAt,
            // NULL is the ordinary case: this member follows the club default
            // and moves with it (ADR-0047).
            'credit_limit_cents' => $creditLimitCents,
        ];
        // Only include mandate_reference key when explicitly provided (even if empty string).
        // Absence of the key triggers auto-generation in the repository when IBAN is present.
        if ($mandateReference !== null) {
            $memberData['mandate_reference'] = $mandateReference;
        }
        $member = $this->membersRepository->create($this->enrichIbanWrite($memberData));

        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::MEMBER,
            entityId: $member['id'],
            // A ceiling of their own is a decision worth a line; following the
            // club default is the ordinary case and is worth none.
            newValues: [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'preferred_language' => $language->value,
            ] + ($creditLimitCents === null ? [] : ['credit_limit_cents' => $creditLimitCents]),
            adminUserId: $adminUserId,
        );

        return MemberAdminDto::fromRow($member);
    }

    public function updateMember(string $memberId, array $updateData, ?string $adminUserId = null): MemberAdminDto
    {
        $oldMember = $this->membersRepository->findById($memberId);
        if (!$oldMember) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        // Email is required at application level (#362): the pre-notification
        // and settlement statement emails are a contractual promise (Nutzungsordnung
        // § 7), so an active member cannot be left without one. The column stays
        // nullable for GDPR erasure (ADR-0029), which nulls it through
        // anonymizeMember() — a separate write this check never sees — after
        // setting the member inactive, so a legitimately anonymized member never
        // reaches this check with an active status. Deactivating a member first
        // (or in the same request) still permits clearing it.
        $staysActive = array_key_exists('is_active', $updateData)
            ? filter_var($updateData['is_active'], FILTER_VALIDATE_BOOLEAN)
            : (bool) $oldMember['is_active'];
        if (
            array_key_exists('email', $updateData)
            && ($updateData['email'] === null || $updateData['email'] === '')
            && $staysActive
        ) {
            throw new ValidationException(
                'The given data was invalid.',
                ['email' => ['email cannot be cleared for an active member']],
            );
        }

        // The fields an update is allowed to carry. This used to be written as
        // a camelCase => snake_case map, but the loop only ever read the
        // snake_case side: the camelCase keys named nothing and translated
        // nothing, so a caller sending `firstName` was silently dropped either
        // way (#120).
        $updatable = [
            'first_name', 'last_name', 'email', 'phone', 'card_uid',
            // A birth date may be *corrected* — the value on file is what the
            // terminal's Jugendschutz check trusts, so a typo has to be fixable
            // (ADR-0045). It cannot be *cleared*: the controller rejects a blank
            // one, because a NULL birth date means an anonymized member and
            // nothing else.
            'date_of_birth',
            'preferred_language', 'is_active', 'iban', 'account_holder_name',
            'mandate_reference', 'mandate_signed_at',
            // Three distinct outcomes, and `array_key_exists` below is what
            // keeps them apart: an absent key leaves the override alone, an
            // explicit null clears it back to the club default, and 0 stores a
            // deliberate "no ceiling for this member" (ADR-0047).
            'credit_limit_cents',
        ];

        $dbUpdateData = [];
        foreach ($updatable as $field) {
            if (array_key_exists($field, $updateData)) {
                $value = $updateData[$field];
                // Convert boolean to int for database (PDO can convert false to empty string)
                if ($field === 'is_active' && is_bool($value)) {
                    $value = (int) $value;
                }
                $dbUpdateData[$field] = $value;
            }
        }

        $member = $this->membersRepository->updateById($memberId, $this->enrichIbanWrite($dbUpdateData));

        $changes = $this->detectChanges($oldMember, $member);
        if (!empty($changes['old'])) {
            $this->auditService->log(
                action: AuditAction::UPDATE,
                entityType: EntityType::MEMBER,
                entityId: $memberId,
                oldValues: $changes['old'],
                newValues: $changes['new'],
                adminUserId: $adminUserId,
            );
        }

        return MemberAdminDto::fromRow($member);
    }

    public function deleteMember(string $memberId, ?string $adminUserId = null): bool
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        // Soft delete: set deleted_at timestamp
        $this->membersRepository->updateById($memberId, [
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by_admin_id' => $adminUserId,
        ]);

        $this->auditService->log(
            action: AuditAction::DELETE,
            entityType: EntityType::MEMBER,
            entityId: $memberId,
            oldValues: ['first_name' => $member['first_name'], 'last_name' => $member['last_name'], 'email' => $member['email']],
            adminUserId: $adminUserId,
        );

        return true;
    }

    public function anonymizeMember(string $memberId, ?string $adminUserId = null): MemberAdminDto
    {
        $member = $this->membersRepository->findByIdIncludingDeleted($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        // Already anonymized?
        if ($member['deleted_at'] !== null) {
            throw new BusinessRuleException('Member already anonymized');
        }

        // Outstanding balance must be €0.00
        $balanceCents = $this->getUnsettledBalanceCents($memberId);
        if ($balanceCents !== 0) {
            $balanceEur = number_format(abs($balanceCents) / 100, 2, '.', '');
            $sign = $balanceCents > 0 ? '' : '-';
            throw new BusinessRuleException("Cannot anonymize: outstanding balance of {$sign}€{$balanceEur}");
        }

        // No pending (non-cancelled) settlement including this member
        if ($this->hasPendingSettlement($memberId)) {
            throw new BusinessRuleException('Cannot anonymize: member included in active settlement');
        }

        // Anonymize the member record, scrub prior audit history and log the
        // anonymization as a single unit — a crash between these writes must
        // not leave a half-anonymized member with intact PII in the audit
        // log. There is no mandate document to drop here (ADR-0037): the
        // system never retains one, so anonymization has nothing left to
        // unlink.
        $this->db->beginTransaction();
        try {
            $anonymized = $this->membersRepository->anonymize($memberId, $adminUserId);
            if (!$anonymized) {
                throw new BusinessRuleException('Anonymization failed');
            }

            // The outbox is the second place this member's address lives
            // (ADR-0029, #408): the queue snapshots it at enqueue so it can
            // prove who was announced to, and a snapshot is exactly what
            // clearing `members.email` does not reach. Inside this transaction
            // rather than after it, because the ADR words it as "both or
            // neither" — an erasure that committed the member row and then
            // failed here would not have erased the address, it would have
            // moved it somewhere nobody looks.
            $this->notificationsService->eraseMember($memberId);

            // Scrub the payload of every historical audit entry about this
            // member (GDPR Art. 17) — every entry keyed to their id, whatever
            // entity type it claims. Entries keyed to *another* id are out of
            // reach by design and must therefore never carry member PII in the
            // first place; {@see AuditLogRepository::scrubByEntityId()} states
            // that invariant and names what enforces it (#115).
            $this->auditLogRepository->scrubByEntityId($memberId);

            // Fetch the updated member record
            $updatedMember = $this->membersRepository->findByIdIncludingDeleted($memberId);

            // Create anonymization audit entry with NO PII
            $this->auditService->log(
                action: AuditAction::ANONYMIZE,
                entityType: EntityType::MEMBER,
                entityId: $memberId,
                newValues: ['deleted_at' => $updatedMember['deleted_at']],
                adminUserId: $adminUserId,
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return MemberAdminDto::fromRow($updatedMember);
    }

    private function getUnsettledBalanceCents(string $memberId): int
    {
        return $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId);
    }

    private function hasPendingSettlement(string $memberId): bool
    {
        return $this->transactionsRepository->hasMemberInActiveSettlement($memberId);
    }

    private function resolveBankName(?string $iban): ?string
    {
        if ($this->bankCodeService === null || $iban === null) {
            return null;
        }
        return $this->bankCodeService->getBankNameForIban($iban);
    }

    private function detectChanges(array $old, array $new): array
    {
        $oldValues = [];
        $newValues = [];
        foreach ($new as $key => $newValue) {
            $oldValue = $old[$key] ?? null;
            if ($oldValue !== $newValue) {
                $oldValues[$key] = $oldValue;
                $newValues[$key] = $newValue;
            }
        }
        return ['old' => $oldValues, 'new' => $newValues];
    }
}
