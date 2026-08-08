<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\Members\DTOs\MemberDto;
use App\Modules\Members\DTOs\MemberAdminDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\DTOs\SyncResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Members\Repositories\MembersRepository;
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
        private PDO $db,
        private ?BankCodeService $bankCodeService = null,
    ) {}

    public function syncSince(int $since): SyncResultDto
    {
        $rows = $this->membersRepository->findModifiedSince($since);
        $members = array_map(fn($row) => MemberDto::fromRow($row), $rows);

        // When no changes: return input cursor to avoid race condition
        // (items created during query execution won't be lost)
        $cursor = !empty($rows)
            ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
            : $since;

        return new SyncResultDto(items: $members, cursor: $cursor, hasMore: false);
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

        // Batch lookup: resolve bank names for all IBANs in one query
        $bankNames = [];
        if ($this->bankCodeService !== null) {
            $ibans = array_filter(array_column($result['items'], 'iban'));
            if (!empty($ibans)) {
                $bankNames = $this->bankCodeService->getBankNamesForIbans($ibans);
            }
        }

        $items = array_map(
            fn($row) => MemberAdminDto::fromRow($row, $bankNames[$row['iban'] ?? ''] ?? null)->toArray(),
            $result['items'],
        );

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
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
        $bankName = $this->resolveBankName($member['iban'] ?? null);
        return MemberAdminDto::fromRow($member, $bankName);
    }

    public function exportMember(string $memberId): array
    {
        $row = $this->membersRepository->findByIdIncludingDeleted($memberId);
        if (!$row) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        $bankName = $this->resolveBankName($row['iban'] ?? null);
        $member = MemberAdminDto::fromRow($row, $bankName);
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
        ?string $adminUserId = null,
    ): MemberAdminDto {
        $memberData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'card_uid' => $cardUid,
            'preferred_language' => $language->value,
            'is_active' => true,
            'iban' => $iban,
            'account_holder_name' => $accountHolderName,
            'mandate_signed_at' => $mandateSignedAt,
        ];
        // Only include mandate_reference key when explicitly provided (even if empty string).
        // Absence of the key triggers auto-generation in the repository when IBAN is present.
        if ($mandateReference !== null) {
            $memberData['mandate_reference'] = $mandateReference;
        }
        $member = $this->membersRepository->create($memberData);

        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::MEMBER,
            entityId: $member['id'],
            newValues: [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'preferred_language' => $language->value,
            ],
            adminUserId: $adminUserId,
        );

        $bankName = $this->resolveBankName($member['iban'] ?? null);
        return MemberAdminDto::fromRow($member, $bankName);
    }

    public function updateMember(string $memberId, array $updateData, ?string $adminUserId = null): MemberAdminDto
    {
        $oldMember = $this->membersRepository->findById($memberId);
        if (!$oldMember) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        $dbUpdateData = [];
        $map = [
            'firstName' => 'first_name', 'lastName' => 'last_name', 'email' => 'email',
            'phone' => 'phone', 'cardUid' => 'card_uid', 'preferredLanguage' => 'preferred_language',
            'isActive' => 'is_active', 'iban' => 'iban', 'accountHolderName' => 'account_holder_name',
            'mandateReference' => 'mandate_reference', 'mandateSignedAt' => 'mandate_signed_at',
        ];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($snake, $updateData)) {
                $value = $updateData[$snake];
                // Convert boolean to int for database (PDO can convert false to empty string)
                if ($snake === 'is_active' && is_bool($value)) {
                    $value = (int) $value;
                }
                $dbUpdateData[$snake] = $value;
            }
        }

        $member = $this->membersRepository->updateById($memberId, $dbUpdateData);

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

        $bankName = $this->resolveBankName($member['iban'] ?? null);
        return MemberAdminDto::fromRow($member, $bankName);
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

        // Anonymize the member record, scrub prior audit history, and log the
        // anonymization as a single unit — a crash between these writes must
        // not leave a half-anonymized member with intact PII in the audit log.
        $this->db->beginTransaction();
        try {
            $anonymized = $this->membersRepository->anonymize($memberId);
            if (!$anonymized) {
                throw new BusinessRuleException('Anonymization failed');
            }

            // Scrub all historical audit log entries for this member (GDPR Art. 17)
            $this->auditLogRepository->scrubByEntityId('member', $memberId);

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
