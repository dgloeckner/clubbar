<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\DTOs\TerminalAnomalyDataDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\TerminalAnomalyMail;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Mail\MailMessage;

/**
 * Renders the terminal anomaly warning at send time (ADR-0041 §4, ADR-0038 rule 5).
 *
 * The first admin-addressed builder in the codebase. `NotificationsService::warnAdmins()`
 * has existed since #438 with nothing able to render what it queues, so until
 * now an admin-addressed row would have been claimed by the drain and recorded
 * as skipped.
 *
 * Which anomaly a row is about comes from the `dedup_key`, not from a column.
 * `warnAdmins()` builds that key as `occasion:adminUserId` and the occasion
 * carries the anomaly's kind and the first eight characters of its id — enough
 * to find the row again, and short enough to fit the column's 64 characters
 * (see {@see \App\Modules\Terminals\Services\TerminalAnomalyDetector::mail()}).
 */
class TerminalAnomalyMailBuilder implements MailContentBuilder
{
    public function __construct(
        private TerminalsRepository $terminalsRepository,
        private TerminalAnomaliesRepository $anomaliesRepository,
        private AdminUsersRepository $adminUsersRepository,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind === MailKind::TERMINAL_ANOMALY_WARNING;
    }

    /**
     * @param array<string,mixed> $outboxRow
     *
     * @throws \RuntimeException When the terminal the row points at is gone.
     *         `subject_id` carries no foreign key — it is polymorphic — so this
     *         is reachable, and a warning about a terminal that no longer
     *         exists must not be invented around the gap.
     */
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
    {
        $terminalId = (string) $outboxRow['subject_id'];
        $terminal = $this->terminalsRepository->findById($terminalId);

        if ($terminal === null) {
            throw new \RuntimeException(sprintf(
                'Cannot build a terminal anomaly warning: terminal %s no longer exists',
                $terminalId,
            ));
        }

        [$kind, $anomalyIdPrefix] = self::parseOccasion((string) $outboxRow['dedup_key']);

        return TerminalAnomalyMail::render(new TerminalAnomalyDataDto(
            language: MailLanguage::from((string) ($outboxRow['language'] ?? MailLanguage::German->value)),
            // The recipient address comes from the row, never re-read from
            // admin_users: it is the snapshot of who was told, and the address
            // may have changed since (the same rule SettlementMailBuilder
            // follows for members).
            recipientAddress: (string) $outboxRow['recipient'],
            recipientName: $this->recipientName($outboxRow),
            branding: $mailConfig->toBranding(),
            terminalName: (string) $terminal['name'],
            kind: $kind,
            evidence: $this->evidence($terminalId, $kind, $anomalyIdPrefix),
        ));
    }

    /**
     * Split `kind:idPrefix:adminUserId` back into its first two parts.
     *
     * @return array{0: string, 1: string}
     */
    public static function parseOccasion(string $dedupKey): array
    {
        $parts = explode(':', $dedupKey);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /**
     * The evidence table, rebuilt from the anomaly row.
     *
     * Matched on the id prefix within this terminal's open anomalies. A prefix
     * rather than a whole id is what fits the dedup key, and eight hex
     * characters across one terminal's handful of anomalies is not a collision
     * risk worth a schema change.
     *
     * If the anomaly has been acknowledged and cleared before the queue drained,
     * there is nothing to show and the message still goes: the admin should
     * learn that something was seen even if a colleague has already dealt with
     * it, and a warning with no table is better than a warning that never
     * arrived.
     *
     * @return array<string,string>
     */
    private function evidence(string $terminalId, string $kind, string $anomalyIdPrefix): array
    {
        foreach ($this->anomaliesRepository->listOpen() as $anomaly) {
            if ((string) $anomaly['terminal_id'] !== $terminalId) {
                continue;
            }
            if ($anomalyIdPrefix !== '' && !str_starts_with((string) $anomaly['id'], $anomalyIdPrefix)) {
                continue;
            }

            return self::describe($kind, json_decode((string) ($anomaly['details'] ?? '{}'), true) ?: []);
        }

        return [];
    }

    /**
     * Turn a `details` payload into labelled lines.
     *
     * Kept beside the builder rather than in the template: what is worth
     * showing differs per kind, and a template that reconstructed it would be a
     * second place for the meaning of the payload to live.
     *
     * @param array<string,mixed> $details
     * @return array<string,string>
     */
    public static function describe(string $kind, array $details): array
    {
        if ($kind === 'concurrent_ip') {
            $rows = [];

            $overlap = (int) ($details['overlap_seconds'] ?? 0);
            $rows['Overlap'] = sprintf('%d min', intdiv($overlap, 60));

            foreach (($details['ips'] ?? []) as $i => $ip) {
                if (!is_array($ip)) {
                    continue;
                }
                $rows[sprintf('Address %d', $i + 1)] = sprintf(
                    '%s (%s – %s)',
                    (string) ($ip['ip_address'] ?? '?'),
                    (string) ($ip['first_seen_at'] ?? '?'),
                    (string) ($ip['last_seen_at'] ?? '?'),
                );
            }

            return $rows;
        }

        return [
            'Stream' => (string) ($details['stream'] ?? '?'),
            'Expected from' => (string) ($details['high_water_cursor'] ?? '?'),
            'Presented' => (string) ($details['presented_cursor'] ?? '?'),
            'Observed at' => (string) ($details['observed_at'] ?? '?'),
        ];
    }

    /** @param array<string,mixed> $outboxRow */
    private function recipientName(array $outboxRow): ?string
    {
        $adminUserId = $outboxRow['admin_user_id'] ?? null;
        if (!is_string($adminUserId) || $adminUserId === '') {
            return null;
        }

        foreach ($this->adminUsersRepository->findActiveRecipients() as $admin) {
            if ((string) $admin['id'] === $adminUserId) {
                $name = trim((string) ($admin['display_name'] ?? ''));

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }
}
