<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Domain\BackupKeyringException;
use App\Modules\Backups\Domain\BackupRecipient;

/**
 * Who this installation seals archives to, right now.
 *
 * **One source: `config.php`.** `backup.recipient_public_keys` names the
 * recipients, and its presence is also what switches backups on (ADR-0049
 * decision 2) — so "keys configured" and "backups on" are one state and can
 * never disagree.
 *
 * An earlier draft had a second source: a `backup_keys.compromised_at`
 * blocklist in the database that outranked the file. It went with the rest of
 * the backup's database state (decision 8), and the defect that killed it is
 * specific rather than tidiness — **a restore reverted it.** An archive
 * predating the compromise carries the pre-compromise table, so importing it
 * silently un-decides a security decision, at exactly the moment somebody is
 * restoring because something went wrong. Compromise handling is now removing
 * the key from `config.php` plus the runbook; who holds which key, and whether
 * one is compromised, lives in the club's key register and minutes.
 *
 * **Every failure here is fatal to the run.** There is no sealing to whichever
 * entries happened to parse, and no plaintext fallback — ADR-0031 rule 3 says
 * refuse and report, and an archive missing the one recipient still in the
 * country is not a smaller problem than no archive, it is a worse one, because
 * it looks like a backup.
 *
 * ADR-0049 decision 2. Part of #690 and #703, epic #686.
 */
final class BackupKeyring
{
    /**
     * `label:hexkey`. The label is deliberately narrow — it is printed by the
     * offline decryptor to tell a holder which envelope in the safe to fetch,
     * so it has to survive a terminal, an HTML table and a phone screen.
     */
    private const ENTRY = '/^(?<label>[A-Za-z0-9_-]{1,64}):(?<key>[0-9a-fA-F]{64})$/';

    /**
     * The recipients an archive written now must be sealed to.
     *
     * @param string $configured newline-separated `label:hexkey` entries
     * @return list<BackupRecipient>
     * @throws BackupKeyringException when no archive could be written at all
     */
    public function recipients(string $configured): array
    {
        $declared = $this->parse($configured);

        if ($declared === []) {
            throw BackupKeyringException::nothingConfigured();
        }

        return $declared;
    }

    /**
     * Parse alone, for the self-check and the installer — both of which want to
     * say "this line is malformed" without attempting a run.
     *
     * @return list<BackupRecipient>
     * @throws BackupKeyringException on a malformed or duplicated entry
     */
    public function parse(string $configured): array
    {
        $recipients = [];
        $seen = [];

        foreach (preg_split('/[\r\n]+/', trim($configured)) ?: [] as $line) {
            $entry = trim($line);
            if ($entry === '') {
                continue;
            }

            if (preg_match(self::ENTRY, $entry, $m) !== 1) {
                throw BackupKeyringException::malformedEntry($entry);
            }

            $label = strtolower($m['label']);
            if (isset($seen[$label])) {
                throw BackupKeyringException::duplicateLabel($label);
            }
            $seen[$label] = true;

            $recipients[] = new BackupRecipient($label, strtolower($m['key']));
        }

        return $recipients;
    }
}
