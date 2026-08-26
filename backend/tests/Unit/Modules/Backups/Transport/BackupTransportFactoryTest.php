<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Transport;

use App\Modules\Backups\Transport\BackupTransportFactory;
use App\Modules\Backups\Transport\MisconfiguredTransport;
use App\Modules\Backups\Transport\MsGraphTransport;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttpClient;

/**
 * Which of the three states `backup.dsn` is in.
 *
 * The middle one is why this class exists. "Empty" and "valid" are easy; the
 * dangerous state is **filled in and wrong**, because the tempting handling —
 * fall back to local-only — hands a club the belief that its archives are off
 * the host while they sit on the same webspace as the database. That belief is
 * what ADR-0049 was written to destroy, and a typo must not be able to
 * re-create it.
 *
 * Part of #691, epic #686.
 */
class BackupTransportFactoryTest extends TestCase
{
    public function test_no_dsn_means_local_only_and_says_nothing(): void
    {
        $this->assertNull($this->build(null));
        $this->assertNull($this->build(''));
        $this->assertNull($this->build('   '));
    }

    public function test_a_valid_dsn_yields_the_transport_it_names(): void
    {
        $this->assertInstanceOf(
            MsGraphTransport::class,
            $this->build('msgraph://t/c@drive/b!x/clubbar')
        );
    }

    /**
     * The failure this whole class is shaped around: a malformed DSN must be a
     * loud failure every run, never a quiet fall back to local-only.
     */
    public function test_a_malformed_dsn_fails_every_run_rather_than_reading_as_no_remote(): void
    {
        $transport = $this->build('msgraph://tenant-only');

        $this->assertInstanceOf(MisconfiguredTransport::class, $transport);

        $result = $transport->upload('/does/not/matter', 60);
        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('backup.dsn', $result->summary);
    }

    public function test_a_planned_but_unbuilt_scheme_is_a_failure_that_explains_itself(): void
    {
        $result = $this->build('s3://key@bucket/path')?->upload('/x', 60);

        $this->assertSame('failed', $result?->status);
        $this->assertStringContainsString('not built yet', (string) $result?->summary);
    }

    /** Half-onboarded is not configured, and is not silent either. */
    public function test_a_dsn_with_no_client_secret_is_refused_rather_than_attempted(): void
    {
        $transport = $this->build('msgraph://t/c@drive/b!x/clubbar', '');

        $this->assertInstanceOf(MisconfiguredTransport::class, $transport);
        $this->assertStringContainsString(
            'backup.client_secret',
            $transport->upload('/x', 60)->summary
        );
    }

    private function build(?string $dsn, ?string $secret = 'secret'): ?object
    {
        return BackupTransportFactory::fromConfig(
            $dsn,
            $secret,
            new FakeHttpClient(),
            $this->createMock(Logger::class),
        );
    }
}
