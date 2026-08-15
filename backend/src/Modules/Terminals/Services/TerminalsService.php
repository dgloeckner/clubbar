<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Services;

use App\Modules\Terminals\DTOs\TerminalDto;
use App\Modules\Terminals\DTOs\TerminalWithTokenDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Services\AuditService;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Modules\Auth\Services\TokenService;
use App\Shared\Config\AppConfig;

class TerminalsService
{
    public function __construct(
        private TerminalsRepository $terminalsRepository,
        private AuditService $auditService,
        private AppConfig $config,
        private TerminalAnomaliesRepository $anomaliesRepository,
    ) {}

    public function listTerminals(int $limit, int $offset, ?bool $isActive = null): PaginatedResultDto
    {
        $result = $this->terminalsRepository->listPaginated($limit, $offset, $isActive);

        // One grouped read for the whole page rather than a query per row
        // (ADR-0041 §4).
        $anomalyCounts = $this->anomaliesRepository->openCountsByTerminal();
        $items = array_map(
            fn($row) => TerminalDto::fromRow(
                $row + ['open_anomaly_count' => $anomalyCounts[$row['id']] ?? 0]
            )->toArray(),
            $result['items'],
        );

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    public function getTerminal(string $terminalId): TerminalDto
    {
        $terminal = $this->terminalsRepository->findById($terminalId);
        if (!$terminal) throw NotFoundException::forResource('Terminal', $terminalId);

        $anomalyCounts = $this->anomaliesRepository->openCountsByTerminal();

        return TerminalDto::fromRow(
            $terminal + ['open_anomaly_count' => $anomalyCounts[$terminal['id']] ?? 0]
        );
    }

    public function createTerminal(string $name, string $deviceId, ?string $adminUserId = null): array
    {
        $existing = $this->terminalsRepository->findByDeviceId($deviceId);
        if ($existing) throw new DuplicateResourceException('Device ID already exists');

        $plainToken = TokenService::generateTerminalToken();
        $hash = TokenService::hashToken($plainToken);

        $terminal = $this->terminalsRepository->create([
            'name' => $name,
            'device_id' => $deviceId,
            'api_token_hash' => $hash,
            'token_ttl_days' => $this->config->tokenTtlDays,
            'is_active' => true,
        ]);

        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminal['id'],
            newValues: [
                'name' => $name,
                'device_id' => $deviceId,
                'token_expires_at' => $terminal['token_expires_at'],
            ],
            adminUserId: $adminUserId,
        );

        // Enrolling a device and issuing it a credential are two facts, and the
        // credential's own trail has to be readable without reconstructing it
        // from terminal CRUD (#395). Enrolment is the one case with nothing to
        // overlap with, so this token is active from the start.
        $this->auditService->log(
            action: AuditAction::TERMINAL_TOKEN_CREATED,
            entityType: EntityType::TERMINAL,
            entityId: $terminal['id'],
            newValues: [
                'device_id' => $deviceId,
                'token_expires_at' => $terminal['token_expires_at'],
            ],
            adminUserId: $adminUserId,
        );

        return [
            'terminal' => TerminalWithTokenDto::fromRowWithToken($terminal, $plainToken),
            'plaintext_token' => $plainToken,
        ];
    }

    public function updateTerminal(string $terminalId, ?string $name = null, ?bool $isActive = null, ?string $adminUserId = null): TerminalDto
    {
        $data = [];
        if ($name !== null) $data['name'] = $name;
        if ($isActive !== null) $data['is_active'] = $isActive ? 1 : 0;

        $terminal = $this->terminalsRepository->updateById($terminalId, $data);
        if (!$terminal) throw NotFoundException::forResource('Terminal', $terminalId);

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: $data,
            adminUserId: $adminUserId,
        );

        return TerminalDto::fromRow($terminal);
    }

    public function deleteTerminal(string $terminalId, ?string $adminUserId = null): void
    {
        $this->terminalsRepository->updateById($terminalId, ['is_active' => 0]);

        $this->auditService->log(
            action: AuditAction::DEACTIVATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: ['is_active' => false],
            adminUserId: $adminUserId,
        );
    }

    /**
     * Issue a replacement token *alongside* the one in the field (#395).
     *
     * Rotation used to be a cut: the old token died the instant the button was
     * pressed, so an admin could only rotate while standing at the terminal, or
     * knowingly take the bar offline until somebody could. With a 365-day
     * lifetime that deadline lands once a year, unannounced, on a device nobody
     * is next to — so the new token is staged as PENDING instead. Both tokens
     * authenticate; the first sync that presents the new one promotes it and
     * retires the old one in the same statement
     * ({@see TerminalTokenAuthenticator}).
     *
     * A terminal whose token has already expired rotates the same way: nothing
     * is overlapping with anything, and the pending token is accepted on its
     * own the moment it is entered.
     */
    public function rotateToken(string $terminalId, ?string $adminUserId = null): array
    {
        $plainToken = TokenService::generateTerminalToken();
        $hash = TokenService::hashToken($plainToken);

        $terminal = $this->terminalsRepository->issuePendingToken($terminalId, $hash, $this->config->tokenTtlDays);

        if (!$terminal) throw NotFoundException::forResource('Terminal', $terminalId);

        $this->auditService->log(
            action: AuditAction::TERMINAL_TOKEN_ROTATED,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: [
                'api_token' => '[ROTATED]',
                'pending_token_expires_at' => $terminal['pending_token_expires_at'],
                // The credential the new one will replace, so the log says what
                // is still live during the overlap rather than only what is new.
                'replaces_token_expires_at' => $terminal['token_expires_at'],
            ],
            adminUserId: $adminUserId,
        );

        return [
            'terminal' => TerminalWithTokenDto::fromRowWithToken($terminal, $plainToken),
            'plaintext_token' => $plainToken,
        ];
    }

    public function revokeAccess(string $terminalId, ?string $adminUserId = null): void
    {
        $this->terminalsRepository->updateById($terminalId, [
            'api_token_hash' => null,
            'token_issued_at' => null,
            'token_expires_at' => null,
            // A staged replacement is a credential too: leaving it behind would
            // let a revoked terminal walk straight back in by presenting the
            // token an admin had prepared before deciding to revoke (#395).
            'pending_token_hash' => null,
            'pending_token_issued_at' => null,
            'pending_token_expires_at' => null,
            'is_active' => 0,
        ]);

        $this->auditService->log(
            action: AuditAction::TERMINAL_TOKEN_REVOKED,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: ['api_token_hash' => null, 'is_active' => false],
            adminUserId: $adminUserId,
        );
    }

    /**
     * Mark a detected anomaly as seen (ADR-0041 §4).
     *
     * This clears the alert and changes nothing about the credential. That is
     * the whole posture: the detector raises a question a human has to answer,
     * and answering it is not the same as acting on it — an admin who decides a
     * terminal really was cloned revokes it separately, through the path that
     * already exists for that.
     *
     * @return bool False when there is no such open anomaly for this terminal —
     *              an unknown id, or one another admin cleared first.
     */
    public function acknowledgeAnomaly(string $terminalId, string $anomalyId, ?string $adminUserId = null): bool
    {
        $anomaly = $this->anomaliesRepository->findById($anomalyId);

        // The terminal in the path has to be the one the anomaly belongs to.
        // Without this the id alone would be the authority, and a mistyped URL
        // would silently clear an alert about a different till.
        if ($anomaly === null || (string) $anomaly['terminal_id'] !== $terminalId) {
            return false;
        }

        if (!$this->anomaliesRepository->acknowledge($anomalyId, (string) $adminUserId, time())) {
            return false;
        }

        $this->auditService->log(
            action: AuditAction::TERMINAL_ANOMALY_ACKNOWLEDGED,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: [
                'anomaly_id' => $anomalyId,
                'kind' => $anomaly['kind'],
                'first_detected_at' => $anomaly['first_detected_at'],
                'occurrence_count' => (int) $anomaly['occurrence_count'],
            ],
            adminUserId: $adminUserId,
        );

        return true;
    }
}
