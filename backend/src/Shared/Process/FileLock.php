<?php

declare(strict_types=1);

namespace App\Shared\Process;

/**
 * A non-blocking `flock` over a file, so a slow run cannot be overtaken by the
 * next one (ADR-0038, #403).
 *
 * The drain already cannot double-send: a message is claimed by an atomic
 * `UPDATE`, so two runs over one queue send exactly N mails and never N+1. This
 * lock is not about correctness of the send, it is about not stacking work.
 * A cron every five minutes against a run that takes twelve will otherwise pile
 * up three concurrent PHP processes on a tariff that allows very few, and the
 * host kills the account rather than the loop.
 *
 * Non-blocking on purpose. A second run that waits is still a second process
 * held open, which is the thing being avoided; a second run that leaves
 * immediately costs nothing and the queue is drained by the run already in
 * progress.
 *
 * The lock is advisory and per-host. On a load-balanced deployment two web
 * nodes hold two different files and both may drain — which is exactly why the
 * claim in `mail_outbox`, not this, is what guarantees each message is sent
 * once.
 */
final class FileLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private string $path) {}

    /**
     * Take the lock, or report that somebody else has it.
     *
     * A file that cannot be created — a data directory the cron user cannot
     * write — is *not* treated as "locked": that would silently stop every
     * future run and present as a queue that never moves. It throws instead, so
     * the cron output names the path.
     *
     * @throws \RuntimeException when the lock file cannot be opened
     */
    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open lock file {$this->path} — is the data directory writable?");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        // The pid is for a human reading the file after a run that never
        // finished; nothing reads it back.
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;

        // The file itself stays. Unlinking it races: another process may have
        // opened this inode and be about to lock it, and it would then hold a
        // lock on a file nobody else can see.
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        $this->release();
    }
}
