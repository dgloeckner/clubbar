<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\DTOs\MemberDto;
use App\Modules\Members\DTOs\MemberAdminDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\DTOs\SyncResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\Members\Repositories\MembersRepository;
use App\Shared\Services\AuditService;

class MembersService
{
    public function __construct(
        private MembersRepository $membersRepository,
        private AuditService $auditService,
    ) {}

    public function syncSince(int $since): SyncResultDto
    {
        $rows = $this->membersRepository->findModifiedSince($since);
        $members = array_map(fn($row) => MemberDto::fromRow($row), $rows);

        $cursor = !empty($rows)
            ? end($rows)['updated_at']
            : date('Y-m-d\TH:i:s\Z');

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
        $items = array_map(fn($row) => MemberAdminDto::fromRow($row)->toArray(), $result['items']);

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    public function getMember(string $memberId): MemberAdminDto
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        return MemberAdminDto::fromRow($member);
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
        $member = $this->membersRepository->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'card_uid' => $cardUid,
            'preferred_language' => $language->value,
            'is_active' => true,
            'iban' => $iban,
            'account_holder_name' => $accountHolderName,
            'mandate_reference' => $mandateReference,
            'mandate_signed_at' => $mandateSignedAt,
        ]);

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

        return MemberAdminDto::fromRow($member);
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
                $dbUpdateData[$snake] = $updateData[$snake];
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

        return MemberAdminDto::fromRow($member);
    }

    public function deleteMember(string $memberId, ?string $adminUserId = null): bool
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        $success = $this->membersRepository->deleteById($memberId);

        $this->auditService->log(
            action: AuditAction::DELETE,
            entityType: EntityType::MEMBER,
            entityId: $memberId,
            oldValues: ['first_name' => $member['first_name'], 'last_name' => $member['last_name'], 'email' => $member['email']],
            adminUserId: $adminUserId,
        );

        return $success;
    }

    public function anonymizeMember(string $memberId, ?string $adminUserId = null): MemberAdminDto
    {
        $oldMember = $this->membersRepository->findById($memberId);
        if (!$oldMember) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        $this->membersRepository->anonymize($memberId);
        $member = $this->membersRepository->findById($memberId);

        $this->auditService->log(
            action: AuditAction::ANONYMIZE,
            entityType: EntityType::MEMBER,
            entityId: $memberId,
            oldValues: ['first_name' => $oldMember['first_name'], 'last_name' => $oldMember['last_name'], 'iban' => '[MASKED]'],
            newValues: ['first_name' => 'DELETED', 'last_name' => 'DELETED'],
            adminUserId: $adminUserId,
        );

        return MemberAdminDto::fromRow($member);
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
