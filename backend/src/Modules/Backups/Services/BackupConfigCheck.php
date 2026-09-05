<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Domain\BackupDsn;
use App\Modules\Backups\Domain\BackupDsnException;
use App\Modules\Backups\Domain\BackupKeyringException;
use App\Modules\Backups\Domain\HiDriveDsn;
use App\Shared\Security\SecurityFinding;
use App\Shared\Security\SecuritySelfCheck;

/**
 * What `config.php`'s backup section actually says, measured rather than assumed.
 *
 * ## Why this exists even after the installer gained a backup screen
 *
 * The installer writes the file safely now (#710), but nothing stops anybody
 * editing it afterwards — and for the two years between a setup and a disaster,
 * hand-editing is exactly what happens. Every failure below is otherwise
 * **silent until a restore**:
 *
 * - a recipient key pasted in base64, refused nightly by a job nobody reads;
 * - a `backup.dsn` with a segment missing, so archives never leave the host
 *   while the club believes they do;
 * - a client secret that expired last month, which looks identical to a working
 *   one from everywhere except the failing upload.
 *
 * ADR-0031 decision 3's rule holds here as everywhere: **a row is green only
 * when the effective state was observed.** So each row reports what the file
 * says, not what it was supposed to say.
 *
 * ## Validated by the real parsers
 *
 * `BackupKeyring` and `BackupDsn` decide, not a second copy of their rules.
 * They already refuse in sentences written for the person reading this report,
 * and a rule kept in two places eventually disagrees with itself — which is the
 * class of defect this whole change came from.
 *
 * Part of #710, epic #686.
 */
final class BackupConfigCheck
{
    private const CATEGORY = SecuritySelfCheck::CATEGORY_BACKUP;

    /** Warn this far ahead of a client secret's expiry, matching ADR-0036's tiers. */
    private const EXPIRY_WARN_DAYS = 30;

    public function __construct(
        private readonly string $recipientPublicKeys,
        private readonly ?string $dsn,
        private readonly ?string $remoteSecret,
        private readonly ?string $clientSecretExpiresAt,
        private readonly ?string $heartbeatUrl = null,
        /** The secret came from the pre-#825 `backup.client_secret` key. */
        private readonly bool $secretFromDeprecatedKey = false,
    ) {
    }

    /** @return list<SecurityFinding> */
    public function findings(): array
    {
        return [
            $this->recipientKeysFinding(),
            ...$this->remoteFindings(),
            ...$this->monitorFindings(),
        ];
    }

    /**
     * Configuring a recipient key *is* the on-switch (ADR-0049 decision 2), so
     * "none configured" is a legitimate state and reports as a warning rather
     * than a failure — a club that has not set backups up has not broken
     * anything. A key that will not parse is a failure: it is an installation
     * that believes it has backups and produces none.
     */
    private function recipientKeysFinding(): SecurityFinding
    {
        $configured = trim($this->recipientPublicKeys);

        if ($configured === '') {
            return SecurityFinding::warn(
                'backup_recipients',
                self::CATEGORY,
                'Backup recipient keys',
                'none configured — no archives are written',
                'Backups are off. Configuring at least one recipient key switches them on: '
                . 'installer step 6, or backup.recipient_public_keys in config.php.'
            );
        }

        try {
            $recipients = (new BackupKeyring())->recipients($configured);
        } catch (BackupKeyringException $e) {
            return SecurityFinding::fail(
                'backup_recipients',
                self::CATEGORY,
                'Backup recipient keys',
                'configured but unusable',
                $e->getMessage()
            );
        }

        $count = count($recipients);

        // Two holders is the organisational requirement, not a cryptographic
        // one: the realistic failure in a Verein is one volunteer leaving with
        // the only key (ADR-0049 decision 2).
        if ($count === 1) {
            return SecurityFinding::warn(
                'backup_recipients',
                self::CATEGORY,
                'Backup recipient keys',
                '1 recipient configured',
                'Add a second recipient. One key holder leaving, or one lost envelope, '
                . 'currently makes every existing archive unreadable forever.'
            );
        }

        return SecurityFinding::pass(
            'backup_recipients',
            self::CATEGORY,
            'Backup recipient keys',
            $count . ' recipients configured'
        );
    }

    /**
     * A monitor watching a job that never runs (#712).
     *
     * Only one state is worth a row, and it is invisible from everywhere else:
     * a `heartbeat_url` configured while `recipient_public_keys` is empty. The
     * club created a check, pasted the URL in, and backups are off — so nothing
     * ever pings it, the check goes red on day one and **stays red for ever**.
     * The club then either chases a failure that is not happening or, far more
     * likely, deletes the check and believes it is monitored.
     *
     * The reverse — backups on with no monitor — is deliberately *not* a row
     * here. It is already the point of the `backup_recipients` and
     * `backup_remote` rows above, and a report that repeats itself is a report
     * people stop reading.
     *
     * @return list<SecurityFinding>
     */
    private function monitorFindings(): array
    {
        $monitor = trim((string) $this->heartbeatUrl);

        if ($monitor === '' || trim($this->recipientPublicKeys) !== '') {
            return [];
        }

        return [SecurityFinding::warn(
            'backup_monitor',
            self::CATEGORY,
            'Backup monitor',
            'a monitor URL is configured but backups are off, so it is never pinged',
            'The check will stay red for ever and tell you nothing. Either switch backups on by '
            . 'configuring a recipient key, or remove backup.heartbeat_url — a red check nobody '
            . 'can fix is one people learn to ignore.'
        )];
    }

    /** @return list<SecurityFinding> */
    private function remoteFindings(): array
    {
        $dsn = trim((string) $this->dsn);

        if ($dsn === '') {
            return [SecurityFinding::warn(
                'backup_remote',
                self::CATEGORY,
                'Off-site backup target',
                'not configured — archives stay on this webspace',
                'A backup on the same hosting account as the database is not off-site: one '
                . 'suspended tariff takes both. Set backup.dsn, or keep copying archives off '
                . 'by hand on a schedule.'
            )];
        }

        try {
            $parsed = BackupDsn::parse($dsn);
        } catch (BackupDsnException $e) {
            // The worst state available: the club typed a DSN and believes its
            // archives are leaving the host.
            return [SecurityFinding::fail(
                'backup_remote',
                self::CATEGORY,
                'Off-site backup target',
                'configured but unusable',
                $e->getMessage()
            )];
        }

        $findings = [SecurityFinding::pass(
            'backup_remote',
            self::CATEGORY,
            'Off-site backup target',
            $parsed->describe()
        )];

        if (trim((string) $this->remoteSecret) === '') {
            $findings[] = SecurityFinding::fail(
                'backup_secret',
                self::CATEGORY,
                'Backup credential',
                'a remote is configured and backup.remote_secret is empty',
                $parsed instanceof HiDriveDsn
                    ? 'The backup user cannot sign in, so nothing is uploaded. Put that user\'s '
                        . 'password in backup.remote_secret — the one from Administration → Users '
                        . 'in the HiDrive web app, not your own account password.'
                    : 'The backup app cannot sign in, so nothing is uploaded. Mint a secret with '
                        . 'scripts/setup-msgraph-backup.ps1 -RotateSecretOnly.'
            );

            return $findings;
        }

        if ($this->secretFromDeprecatedKey) {
            // A pass, not a warning. Nothing is broken and nothing will break:
            // the old key is read and goes on being read. Amber is reserved for
            // what is actually wrong — a security page whose warnings cannot be
            // cleared is a page that gets skimmed, which is the same argument
            // the expiry finding makes below by being absent.
            $findings[] = SecurityFinding::pass(
                'backup_secret_key',
                self::CATEGORY,
                'Backup credential key',
                'read from backup.client_secret, which is now called backup.remote_secret — '
                . 'both work, rename it in config.php when convenient'
            );
        }

        // A HiDrive password does not expire, so there is nothing here to
        // measure — and UNKNOWN would be a permanent amber row that no action
        // can ever clear. Silence is the honest answer, and the reason this is
        // a scheme test rather than an "is the date set" test.
        if ($parsed instanceof HiDriveDsn) {
            return $findings;
        }

        $findings[] = $this->secretExpiryFinding();

        return $findings;
    }

    /**
     * The date nobody else will tell the club about.
     *
     * Entra sends no notification when a client secret expires, the nightly job
     * keeps writing and sealing its archive, and only the half that takes it off
     * the host stops. Unset, this cannot be measured at all — `UNKNOWN`, never a
     * pass, which is ADR-0031 decision 3's rule doing its job.
     */
    private function secretExpiryFinding(): SecurityFinding
    {
        $configured = trim((string) $this->clientSecretExpiresAt);

        if ($configured === '') {
            return SecurityFinding::unknown(
                'backup_secret',
                self::CATEGORY,
                'Backup credential expiry',
                'backup.client_secret_expires_at is not set',
                'Without the date nothing can warn you before the secret lapses, and Microsoft '
                . 'will not. Record it from the setup script, and put a calendar reminder in too.'
            );
        }

        $expiresAt = strtotime($configured);
        if ($expiresAt === false) {
            return SecurityFinding::unknown(
                'backup_secret',
                self::CATEGORY,
                'Backup credential expiry',
                'backup.client_secret_expires_at is not a date: ' . $configured,
                'Write it as YYYY-MM-DD.'
            );
        }

        $daysLeft = (int) floor(($expiresAt - time()) / 86400);

        if ($daysLeft < 0) {
            return SecurityFinding::fail(
                'backup_secret',
                self::CATEGORY,
                'Backup credential expiry',
                'expired ' . abs($daysLeft) . ' day(s) ago',
                'Uploads have been failing since then; the archives are still on the webspace. '
                . 'Rotate with scripts/setup-msgraph-backup.ps1 -RotateSecretOnly.'
            );
        }

        if ($daysLeft <= self::EXPIRY_WARN_DAYS) {
            return SecurityFinding::warn(
                'backup_secret',
                self::CATEGORY,
                'Backup credential expiry',
                'expires in ' . $daysLeft . ' day(s)',
                'Rotate before it lapses: the old secret keeps working until you delete it, so '
                . 'rotation costs no downtime.'
            );
        }

        return SecurityFinding::pass(
            'backup_secret',
            self::CATEGORY,
            'Backup credential expiry',
            'expires in ' . $daysLeft . ' day(s)'
        );
    }
}
