<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Backups\Transport\BackupTransport;
use App\Modules\Backups\Transport\BackupTransportException;
use App\Modules\Backups\Transport\RemoteArchive;
use App\Modules\Backups\Transport\TransportResult;

/**
 * A remote store the run can be pointed at without a network.
 *
 * {@see \Tests\Support\FakeHttpClient} scripts the *conversation* a real
 * transport has; this scripts the *outcome* one reports, which is what
 * {@see \App\Modules\Backups\Services\BackupService} actually branches on —
 * whether the archive got there, whether the failure became a finding rather
 * than a failed run, whether retention ran at all.
 *
 * Part of #691, epic #686.
 */
final class FakeBackupTransport implements BackupTransport
{
    /** @var list<string> basenames, in the order they were offered */
    public array $uploaded = [];

    /** @var list<RemoteArchive> */
    public array $deleted = [];

    /** @var list<RemoteArchive> */
    private array $stored = [];

    private ?string $listFailure = null;
    private bool $deleteRefused = false;

    public function __construct(private readonly string $status = 'uploaded')
    {
    }

    /** @param list<RemoteArchive> $archives */
    public function holding(array $archives): self
    {
        $this->stored = $archives;

        return $this;
    }

    public function refusingToList(string $why): self
    {
        $this->listFailure = $why;

        return $this;
    }

    public function refusingToDelete(): self
    {
        $this->deleteRefused = true;

        return $this;
    }

    public function describe(): string
    {
        return 'msgraph://test-store/backups';
    }

    public function upload(string $localPath, int $budgetSeconds): TransportResult
    {
        $this->uploaded[] = basename($localPath);

        return match ($this->status) {
            'uploaded' => TransportResult::uploaded('backups/' . basename($localPath), 1024),
            'partial' => TransportResult::partial('backups/' . basename($localPath), 512, 512, 2048),
            default => TransportResult::failed('the fake remote refused'),
        };
    }

    public function list(): array
    {
        if ($this->listFailure !== null) {
            throw new BackupTransportException($this->listFailure);
        }

        return $this->stored;
    }

    public function delete(RemoteArchive $archive): bool
    {
        if ($this->deleteRefused) {
            return false;
        }

        $this->deleted[] = $archive;

        return true;
    }
}
