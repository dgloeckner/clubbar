<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\DTOs\OpenMandatePrintDto;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Registrations\Documents\MandateDocumentService;
use App\Modules\Registrations\Documents\MandateDocumentVariant;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PDO;

/**
 * Print the club's Anmeldung for every member still waiting for a signature,
 * as one PDF (UC-A18; ADR-0052 and ADR-0036 as amended 2026-11-28).
 *
 * This is the middle step of "accept first, print in bulk, sign later". A
 * registration accepted ahead of the paper leaves a member whose mandate has
 * no signature date; this is how the paper they still owe gets printed —
 * forty at a time, the evening forty people are standing in the clubhouse.
 *
 * ## Two prints, and the difference is one secret
 *
 * - **Blank** (`$openIban` null): the IBAN line is left for a pen and
 *   `endet auf ****3000` says what the pen should end on. Needs nothing this
 *   server does not already hold, so it needs no step-up either.
 * - **Keyed** (`$openIban` given): the line is filled in. The server still
 *   holds no key — the opener exists only inside
 *   {@see \App\Modules\Security\Services\EncryptionKeyService::withActivePrivateKey()},
 *   for one request, exactly as it does for the SEPA export.
 *
 * ## Nothing is collected
 *
 * Each ciphertext is read and opened **as its member's sheet is drawn**, and
 * the plaintext goes straight into that sheet's values. There is no list of
 * IBANs at any point, nothing is logged, and nothing is stored: not the
 * template, not the output (ADR-0037 — the signed paper is the Beleg, and this
 * system keeps no copy of it).
 *
 * ## One member never fails the batch
 *
 * A mandate sealed under a key other than the active one cannot be opened with
 * the key in hand. That is an ordinary state in the middle of a rotation, not
 * an error, and the member's sheet is printed blank instead — reported in the
 * result, and audited as what it was.
 */
class OpenMandatePrintService
{
    public function __construct(
        private MembersRepository $members,
        private MandateDocumentService $documents,
        private EncryptionKeysRepository $encryptionKeys,
        private AuditService $audit,
        // The handle itself: one entry per member, and either every sheet in
        // the document is on the record or none is.
        private PDO $db,
        private Logger $logger,
    ) {}

    /**
     * @param list<string>|null $memberIds narrow the print to these members;
     *        null for everybody with an open mandate. An id that is not
     *        waiting for a signature is simply not in the document
     * @param null|callable(string): string $openIban opens a ciphertext sealed
     *        under the ACTIVE key; null for the blank print
     *
     * @throws BusinessRuleException nobody is waiting for a signature, or the
     *         club's document is unreachable or unusable
     */
    public function render(?array $memberIds, ?callable $openIban, ?string $adminUserId = null): OpenMandatePrintDto
    {
        $members = $this->members->findOpenMandates($memberIds);

        if ($members === []) {
            throw new BusinessRuleException(
                BusinessRuleReason::NO_OPEN_MANDATES,
                'No active member has a mandate waiting for a signature.',
            );
        }

        $document = $this->documents->forOpenMandates(
            $members,
            $openIban === null ? null : $this->plainIbanReader($openIban),
        );

        // Rendered before any entry is written, deliberately: an entry saying
        // a full IBAN left the building when the render then failed is worse
        // than no entry, because it records something that did not happen.
        // And *all* of them or none — a document whose audit trail names half
        // its sheets is not handed over.
        $this->db->beginTransaction();
        try {
            foreach ($members as $member) {
                $this->auditSheet($member, $document->variants[(string) $member['id']], $adminUserId);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $sheets = $document->sheetCount();
        $withIban = $document->fullIbanCount();

        // Counts only. Never a name, never four digits.
        $this->logger->info('Open mandates printed', [
            'sheets' => $sheets,
            'with_full_iban' => $withIban,
            'keyed' => $openIban !== null,
        ]);

        return new OpenMandatePrintDto(
            pdf: $document->pdf,
            sheetCount: $sheets,
            withoutIbanCount: $openIban === null ? 0 : $sheets - $withIban,
        );
    }

    /**
     * Turn the request's opener into "this member's plaintext, or null".
     *
     * The key id is checked **before** the opener is called, on the row that
     * carries the ciphertext. Calling it on a box sealed under another key
     * would not leak anything — libsodium simply refuses — but a refusal is an
     * exception, and the difference between "sealed under last year's key" and
     * "corrupt" deserves better than one catch block for both.
     *
     * @param callable(string): string $openIban
     * @return callable(array<string, mixed>): ?string
     */
    private function plainIbanReader(callable $openIban): callable
    {
        $activeKeyId = (string) ($this->encryptionKeys->findActive()['id'] ?? '');

        return function (array $member) use ($openIban, $activeKeyId): ?string {
            $sealed = $this->members->findSealedIban((string) $member['id']);

            if ($sealed === null || (string) $sealed['encryption_key_id'] !== $activeKeyId) {
                return null;
            }

            try {
                return $openIban((string) $sealed['iban_ciphertext']);
            } catch (\Throwable $e) {
                // The right key and it still will not open: the stored box is
                // damaged. Worth a line an operator can find — by member id,
                // which is not personal data on its own — and not worth the
                // other thirty-nine sheets.
                $this->logger->warning('A sealed IBAN could not be opened for the mandate print', [
                    'member_id' => (string) $member['id'],
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        };
    }

    /**
     * One entry per member, saying what their sheet carried.
     *
     * No audit action is added for this (which would mean a migration on an
     * ENUM column). The two that exist already say it:
     *
     * - a sheet with the full IBAN is precisely ADR-0036's "exceptional
     *   full-IBAN display" — {@see AuditAction::IBAN_FULL_VIEW};
     * - a blank one is the Anmeldung printed with an IBAN hint, which is what
     *   {@see AuditAction::REGISTRATION_PRINTED} has always recorded. The
     *   entity is the *member* here, and the `variant` tells the two prints
     *   apart.
     *
     * Keyed to the member, so {@see \App\Modules\AuditLog\Repositories\AuditLogRepository::scrubByEntityId()}
     * reaches both on erasure — and carrying only the masked value in any case
     * (ADR-0005).
     *
     * @param array<string, mixed> $member
     */
    private function auditSheet(array $member, MandateDocumentVariant $variant, ?string $adminUserId): void
    {
        $printedInFull = $variant === MandateDocumentVariant::ADMIN_PRINT_FULL;

        $this->audit->log(
            action: $printedInFull ? AuditAction::IBAN_FULL_VIEW : AuditAction::REGISTRATION_PRINTED,
            entityType: EntityType::MEMBER,
            entityId: (string) $member['id'],
            newValues: [
                'variant' => $printedInFull ? 'open_mandate_full_iban' : 'open_mandate',
                'mandate_reference' => (string) $member['mandate_reference'],
                // The masked value only, on both: what the log holds is that
                // the number was printed, never the number.
                'iban' => '****' . $member['iban_last4'],
            ],
            adminUserId: $adminUserId,
        );
    }
}
