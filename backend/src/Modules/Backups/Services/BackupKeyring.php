<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Domain\BackupKeyringException;
use App\Modules\Backups\Domain\BackupRecipient;
use App\Modules\Backups\Repositories\BackupKeysRepository;

/**
 * Who this installation seals archives to, right now.
 *
 * Two sources, and the precedence between them is the whole point of this
 * class. `config.php` declares the recipients; `backup_keys.compromised_at`
 * can take one away, and **the database wins** — the same precedence
 * `mail_config.cron_secret_hash` already has over `cron.secret` (#473).
 *
 * That direction is deliberate. A compromised key has to stop being sealed to
 * *now*, and on the hosting this targets "now" cannot mean "once somebody
 * edits a file over FTP". It also has to stay gone: a blocklist that the file
 * could override would let a key come back the moment nobody remembered why it
 * was removed.
 *
 * **Every failure here is fatal to the run.** There is no sealing to whichever
 * entries happened to parse, and no plaintext fallback — ADR-0031 rule 3 says
 * refuse and report, and an archive missing the one recipient still in the
 * country is not a smaller problem than no archive, it is a worse one, because
 * it looks like a backup.
 *
 * ADR-0049 decision 2. Part of #690, epic #686.
 */
final class BackupKeyring
{
    /**
     * `label:hexkey`. The label is deliberately narrow — it is printed by the
     * offline decryptor to tell a holder which envelope in the safe to fetch,
     * so it has to survive a terminal, an HTML table and a phone screen.
     */
    private const ENTRY = '/^(?<label>[A-Za-z0-9_-]{1,64}):(?<key>[0-9a-fA-F]{64})$/';

    public function __construct(private readonly BackupKeysRepository $keys)
    {
    }

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

        $blocked = $this->keys->compromisedFingerprints();

        $usable = array_values(array_filter(
            $declared,
            static fn (BackupRecipient $r): bool => !in_array($r->fingerprint(), $blocked, true)
        ));

        if ($usable === []) {
            throw BackupKeyringException::everyKeyCompromised(
                array_map(static fn (BackupRecipient $r): string => $r->label, $declared)
            );
        }

        return $usable;
    }

    /**
     * Parse without consulting the database, for the self-check and the
     * installer — both of which want to say "this line is malformed" without
     * needing a schema.
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
