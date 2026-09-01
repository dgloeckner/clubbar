<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Services;

use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\Members\DTOs\MemberAdminDto;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Registrations\DTOs\PendingRegistrationDto;
use App\Modules\Registrations\Repositories\RegistrationsRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Logging\Logger;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use PDO;

/**
 * The admin half of self-registration (#779, UC-A17, ADR-0052).
 *
 * {@see RegistrationsService} is the surface a stranger reaches; this is the
 * one an authenticated admin does, and almost everything about it is the
 * opposite. That one refuses uniformly and says nothing; this one names its
 * refusals so the panel can explain them. That one logs no identities; this one
 * audits every act, because each is a person acting on somebody else's data.
 *
 * ## Approval is the only door
 *
 * There is no other path from a pending registration to a `members` row — no
 * job, no sync, no import. That is what guarantees a self-registered person
 * cannot reach the terminal before the paper check: the mandate gate (ADR-0020)
 * and the card step (ADR-0021) sit behind this method, and this method requires
 * an admin to state, explicitly, that they are holding the signed form.
 *
 * ## The plaintext is gone, and that shapes everything below
 *
 * By approval time the IBAN has been sealed for days or weeks under a key this
 * server does not hold. Nothing here can read it, so nothing here re-derives
 * anything from it: the ciphertext, its last four, its fingerprint and its key
 * id travel to the mandate as one unit, verbatim. The only place a plaintext
 * IBAN exists in this class is {@see update()}, for the length of one request,
 * when an admin types a corrected one in.
 */
class RegistrationReviewService
{
    /**
     * The fields an admin may correct on a submission.
     *
     * `iban` is handled separately — it is not a column, it is a request to
     * re-run the sealing path. Everything the applicant cannot have got wrong
     * (the reference, the notice URL, the timestamps) is absent, and absent
     * from the repository's allow-list too, so neither layer is the only guard.
     */
    private const EDITABLE = [
        'first_name', 'last_name', 'email', 'phone',
        'date_of_birth', 'preferred_language', 'account_holder_name',
    ];

    public function __construct(
        private RegistrationsRepository $registrations,
        private MembersRepository $members,
        private AuditService $audit,
        private EncryptionKeysRepository $encryptionKeys,
        private BankCodeService $bankCodes,
        private IbanSealedBox $sealedBox,
        private PDO $db,
        private Logger $logger,
    ) {}

    /**
     * The review inbox, one page, with the duplicate flags resolved.
     */
    public function list(
        int $limit,
        int $offset,
        string $sortKey = 'submitted_at',
        string $sortOrder = 'desc',
        ?string $search = null,
    ): PaginatedResultDto {
        $result = $this->registrations->listPaginated($limit, $offset, $sortKey, $sortOrder, $search);

        $items = array_map(
            fn(array $row): array => PendingRegistrationDto::fromRow($row, $this->duplicatesFor($row))->toArray(),
            $this->withDuplicateMarkers($result['items']),
        );

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    public function get(string $id): PendingRegistrationDto
    {
        $row = $this->requireRow($id);
        $rows = $this->withDuplicateMarkers([$row]);

        return PendingRegistrationDto::fromRow($rows[0], $this->duplicatesFor($rows[0]));
    }

    /**
     * Correct a submission before approving it — a typo in a name, a mistyped
     * address, an IBAN off by a digit.
     *
     * An IBAN correction is not a field write. The value arrives as plaintext,
     * is sealed under **today's** active key, and replaces all four sealed
     * columns at once; the bank name is re-resolved from the new BLZ, because
     * once sealed there is nothing left to resolve it from.
     *
     * @param array<string, mixed> $data validated by the controller
     */
    public function update(string $id, array $data, ?string $adminUserId = null): PendingRegistrationDto
    {
        $before = $this->requireRow($id);

        $writes = array_intersect_key($data, array_flip(self::EDITABLE));

        if (($data['iban'] ?? null) !== null && $data['iban'] !== '') {
            $writes += $this->sealedColumnsFor((string) $data['iban']);
        }

        $after = $this->registrations->updateById($id, $writes);
        if ($after === null) {
            throw NotFoundException::forResource('Registration', $id);
        }

        $changes = $this->describeChanges($before, $after);
        if ($changes !== []) {
            $this->audit->log(
                action: AuditAction::REGISTRATION_EDITED,
                entityType: EntityType::REGISTRATION,
                entityId: $id,
                oldValues: $changes['old'],
                newValues: $changes['new'],
                adminUserId: $adminUserId,
            );
        }

        return PendingRegistrationDto::fromRow($after, $this->duplicatesFor($this->withDuplicateMarkers([$after])[0]));
    }

    /**
     * Turn a submission into a member and a mandate.
     *
     * @param string $mandateSignedAt the date on the paper in the admin's hand
     * @param bool $attestationConfirmed the admin's statement that they hold the
     *        signed mandate and — where the club printed the form with the IBAN
     *        left blank — that the hand-written number matches the `****last4`
     *        on file. Not a checkbox on a form: it is the attestation the whole
     *        endpoint exists to record, so a missing one is a refusal, never a
     *        default
     *
     * @throws NotFoundException no such submission, or somebody else just acted on it
     * @throws BusinessRuleException no attestation, or the club already has a
     *         member at this address
     */
    public function approve(
        string $id,
        string $mandateSignedAt,
        bool $attestationConfirmed,
        ?string $adminUserId = null,
    ): MemberAdminDto {
        $row = $this->requireRow($id);

        if (!$attestationConfirmed) {
            throw new BusinessRuleException(
                BusinessRuleReason::REGISTRATION_ATTESTATION_REQUIRED,
                'Approval requires confirming that the signed SEPA mandate is on hand.',
            );
        }

        // Checked here rather than left to the database, which would not have
        // refused it: `members.email` carries no UNIQUE constraint, so the
        // approval would have succeeded and left the club with two member
        // records for one person — discovered at the next settlement, when both
        // received a statement. The review list flags this before an admin gets
        // here; this is the backstop for the flag being missed, or for a member
        // created in between.
        if ($this->members->findExistingEmails([$row['email']]) !== []) {
            throw new BusinessRuleException(
                BusinessRuleReason::REGISTRATION_MEMBER_EMAIL_EXISTS,
                'A member with this email address already exists.',
                ['email' => (string) $row['email']],
            );
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $member = $this->members->createFromSealedMandate(
                [
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'date_of_birth' => $row['date_of_birth'],
                    'preferred_language' => $row['preferred_language'],
                    'account_holder_name' => $row['account_holder_name'],
                ],
                [
                    // Verbatim, all of it. See createFromSealedMandate() for why
                    // the key id in particular is copied and not replaced.
                    'reference' => $row['mandate_reference'],
                    'iban_ciphertext' => $row['iban_ciphertext'],
                    'iban_last4' => $row['iban_last4'],
                    'iban_fingerprint' => $row['iban_fingerprint'],
                    'encryption_key_id' => $row['encryption_key_id'],
                    'bank_name' => $row['bank_name'],
                    'signed_at' => $mandateSignedAt,
                    'created_by_admin_id' => $adminUserId,
                ],
            );

            // Not `false` from a slow hand, but from a second admin approving
            // the same submission in a parallel request: they got the row too,
            // and one of the two must not produce a second member. Whoever
            // deletes it wins; the loser rolls back entirely.
            if (!$this->registrations->deleteById($id)) {
                throw NotFoundException::forResource('Registration', $id);
            }

            // Inside the transaction, deliberately. An approval whose audit
            // entry failed to write would be a member and a mandate coming into
            // existence with no record of who attested to the paper behind them
            // — which is the one thing this entry exists to make impossible.
            $this->audit->log(
                action: AuditAction::REGISTRATION_APPROVED,
                entityType: EntityType::REGISTRATION,
                entityId: $id,
                newValues: [
                    'member_id' => $member['id'],
                    'mandate_reference' => $row['mandate_reference'],
                    'mandate_signed_at' => $mandateSignedAt,
                    // The masked value and nothing else (ADR-0005). Never the
                    // ciphertext, and never the fingerprint — which is a stable
                    // identifier for a bank account and would let anyone
                    // holding a candidate IBAN confirm it from the log.
                    'iban' => '****' . $row['iban_last4'],
                    'signed_mandate_confirmed' => true,
                ],
                adminUserId: $adminUserId,
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->logger->info('Self-registration approved', ['registration_id' => $id, 'member_id' => $member['id']]);

        return MemberAdminDto::fromRow($member);
    }

    /**
     * Delete a submission without creating anything.
     *
     * Immediate, not a status change: a rejected application is data about
     * somebody who is not becoming a member, and there is no reason to keep it
     * (decision 10). The audit entry is what survives, and it carries the
     * reason — which is the only record of *why*, since the row it describes is
     * gone by the time anyone reads it.
     */
    public function reject(string $id, ?string $reason = null, ?string $adminUserId = null): void
    {
        $row = $this->requireRow($id);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            if (!$this->registrations->deleteById($id)) {
                throw NotFoundException::forResource('Registration', $id);
            }

            $this->audit->log(
                action: AuditAction::REGISTRATION_REJECTED,
                entityType: EntityType::REGISTRATION,
                entityId: $id,
                oldValues: [
                    'email' => $row['email'],
                    'iban' => '****' . $row['iban_last4'],
                ],
                newValues: ['reason' => $reason],
                adminUserId: $adminUserId,
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->logger->info('Self-registration rejected', ['registration_id' => $id]);
    }

    /**
     * Seal a plaintext IBAN into the four columns that travel together.
     *
     * The last moment the plaintext exists on this path, so everything that
     * needs to read it happens here, in one place: the bank lookup, the
     * fingerprint, and the seal.
     *
     * @return array<string, mixed>
     */
    private function sealedColumnsFor(string $iban): array
    {
        $normalized = IbanSealedBox::normalize($iban);
        $key = $this->encryptionKeys->requireOperationalActive();

        return [
            'iban_ciphertext' => $this->sealedBox->seal($normalized, $key['public_key']),
            'iban_last4' => IbanSealedBox::lastFour($normalized),
            'iban_fingerprint' => $this->sealedBox->fingerprint($normalized),
            'encryption_key_id' => $key['id'],
            'bank_name' => $this->bankCodes->getBankNameForIban($normalized),
        ];
    }

    /** @return array<string, mixed> */
    private function requireRow(string $id): array
    {
        $row = $this->registrations->findById($id);
        if ($row === null) {
            throw NotFoundException::forResource('Registration', $id);
        }

        return $row;
    }

    /**
     * Ask the members table, once, which of a page's applicants it already
     * knows — and stamp the answer onto each row.
     *
     * Two queries for a page of any size. Doing it per row would be twenty
     * round trips for a screen an admin refreshes while working through the
     * queue.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withDuplicateMarkers(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $emails = $this->members->findExistingEmails(array_column($rows, 'email'));
        $fingerprints = $this->members->findExistingIbanFingerprints(array_column($rows, 'iban_fingerprint'));

        return array_map(
            static fn(array $row): array => $row + [
                '__duplicate_email' => in_array($row['email'], $emails, true),
                '__duplicate_iban' => in_array($row['iban_fingerprint'], $fingerprints, true),
            ],
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row a row that has been through {@see withDuplicateMarkers()}
     * @return array{email: bool, iban: bool}
     */
    private function duplicatesFor(array $row): array
    {
        return [
            'email' => (bool) ($row['__duplicate_email'] ?? false),
            'iban' => (bool) ($row['__duplicate_iban'] ?? false),
        ];
    }

    /**
     * What actually changed, for the audit entry — with the sealed columns
     * reduced to the masked value.
     *
     * An edit that changed nothing writes no entry at all. A log full of "Lena
     * Brandt edited, no fields changed" is a log nobody reads, and this endpoint
     * is reached by a form that submits every field whether or not it was
     * touched.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{old: array<string, mixed>, new: array<string, mixed>}|array{}
     */
    private function describeChanges(array $before, array $after): array
    {
        $watched = array_merge(self::EDITABLE, ['bank_name']);

        $old = [];
        $new = [];
        foreach ($watched as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $old[$field] = $before[$field] ?? null;
                $new[$field] = $after[$field] ?? null;
            }
        }

        // One line for the account, never the four columns behind it: the
        // ciphertext is the sealed IBAN and the fingerprint identifies the
        // account to anyone holding a candidate number.
        if (($before['iban_fingerprint'] ?? null) !== ($after['iban_fingerprint'] ?? null)) {
            $old['iban'] = '****' . ($before['iban_last4'] ?? '');
            $new['iban'] = '****' . ($after['iban_last4'] ?? '');
        }

        return $old === [] ? [] : ['old' => $old, 'new' => $new];
    }
}
