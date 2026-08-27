<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Transport\BackupTransport;
use App\Modules\Backups\Transport\BackupTransportException;
use App\Modules\Backups\Transport\RemoteArchive;
use App\Shared\Logging\Logger;

/**
 * Which archives are off-site — asked live where that is affordable, and read
 * from last night's snapshot where it is not (#693, ADR-0049).
 *
 * ## The enrichment half
 *
 * {@see BackupsInventory} answers everything the filesystem knows, instantly.
 * This answers the one question it cannot, and it is deliberately a **separate
 * request**: the page renders its table first and fills this column in when it
 * arrives. A throttled tenant costs a club one greyed-out column, never the
 * list of what they have.
 *
 * That separation is why asking the store is allowed here at all. The rule the
 * self-check and the every-page banner live under — never reach the provider,
 * asserted on their constructors — exists because a tenant outage must not sit
 * between an admin and every screen they own. On a page whose subject *is* the
 * store, on a request of its own, that reasoning does not apply.
 *
 * ## Three outcomes, and the reader is told which
 *
 * | `source` | Means |
 * |---|---|
 * | `live` | The store was asked just now and answered |
 * | `snapshot` | The store could not be reached; this is what the nightly run last saw, with its date |
 * | `unavailable` | No live answer and no snapshot — nobody has ever successfully listed this store |
 *
 * Never collapsed into two. "The store says this archive is gone" and "we could
 * not ask, and last night it was there" lead a club to different actions, and a
 * page that showed the second as the first would send somebody hunting for a
 * backup that exists.
 *
 * ## Bounded, and a timeout is not an error
 *
 * The transport handed in here is {@see \App\Modules\Backups\Transport\BackupTransportFactory::forBrowsing()}'s
 * — eight seconds, no retries — rather than the nightly one. A call that does
 * not answer inside that is not a failure to report to the user as one: it is
 * the expected cost of a throttled tenant, and the snapshot is the answer.
 *
 * Part of #693, epic #686.
 */
final class RemoteLookup
{
    public const SOURCE_LIVE = 'live';
    public const SOURCE_SNAPSHOT = 'snapshot';
    public const SOURCE_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly RemoteInventory $inventory,
        private readonly Logger $logger,
        /**
         * Null on an installation with no `backup.dsn`, which is a legitimate
         * state: a club may keep local archives and carry them off by hand.
         */
        private readonly ?BackupTransport $transport = null,
    ) {
    }

    /**
     * @return array{
     *     source: string, remote: ?string, taken_at: ?int,
     *     names: list<string>, error: ?string
     * }
     */
    public function look(): array
    {
        $live = $this->live();
        if ($live !== null) {
            return $live;
        }

        $snapshot = $this->inventory->read();
        if ($snapshot !== null) {
            return [
                'source' => self::SOURCE_SNAPSHOT,
                'remote' => $snapshot['remote'],
                // The date is the whole point of returning a snapshot rather
                // than nothing: a reader has to be able to weigh how old this
                // is, and "as of last night" is a different claim from "now".
                'taken_at' => $snapshot['taken_at'],
                'names' => $snapshot['names'],
                'error' => null,
            ];
        }

        return [
            'source' => self::SOURCE_UNAVAILABLE,
            'remote' => $this->transport?->describe(),
            'taken_at' => null,
            'names' => [],
            'error' => null,
        ];
    }

    /**
     * The store, asked now — or null when it could not be reached in time.
     *
     * Null rather than an exception, because every caller's response is the
     * same and correct: fall back. Distinguishing a timeout from a refusal
     * would only create a branch that can be got wrong, and the club's action
     * is identical either way.
     *
     * @return array{source: string, remote: ?string, taken_at: ?int, names: list<string>, error: ?string}|null
     */
    private function live(): ?array
    {
        if ($this->transport === null) {
            return null;
        }

        try {
            $archives = $this->transport->list();
        } catch (BackupTransportException $e) {
            // Logged once per request rather than swallowed silently: a store
            // that has started refusing is worth a trail, and this path is not
            // hot — it runs when somebody opens one page.
            $this->logger->warning('Backups page could not list the remote store', [
                'remote' => $this->transport->describe(),
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'source' => self::SOURCE_LIVE,
            'remote' => $this->transport->describe(),
            'taken_at' => time(),
            'names' => array_values(array_map(
                static fn (RemoteArchive $archive): string => $archive->name,
                $archives
            )),
            'error' => null,
        ];
    }
}
