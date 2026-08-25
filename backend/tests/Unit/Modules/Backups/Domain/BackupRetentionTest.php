<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Domain;

use App\Modules\Backups\Domain\BackupRetention;
use PHPUnit\Framework\TestCase;

/**
 * Compiled defaults, optional `config.php` overrides — and what happens to an
 * override that cannot have been meant.
 *
 * The refusal is the part worth testing. Zero-day retention would delete
 * tonight's archive at the end of tonight's run, and a zero cap would put every
 * installation permanently over it and report so nightly. Both read as a typo
 * or as an unset value that reached here as `0`, and the compiled default is a
 * better guess than either — a scheduled job at 03:00 is the wrong place to
 * discover that a config file says something impossible.
 *
 * Part of #703, epic #686.
 */
class BackupRetentionTest extends TestCase
{
    public function test_an_installation_that_configures_nothing_gets_the_shipped_policy(): void
    {
        $retention = BackupRetention::defaults();

        $this->assertSame(BackupRetention::DEFAULT_LOCAL_DAYS, $retention->localDays);
        $this->assertSame(BackupRetention::DEFAULT_LOCAL_MAX_BYTES, $retention->localMaxBytes);
        $this->assertSame(BackupRetention::DEFAULT_REMOTE_DAYS, $retention->remoteDays);
    }

    public function test_each_value_can_be_overridden_on_its_own(): void
    {
        $retention = BackupRetention::fromOverrides(localDays: 7);

        $this->assertSame(7, $retention->localDays);
        $this->assertSame(
            BackupRetention::DEFAULT_LOCAL_MAX_BYTES,
            $retention->localMaxBytes,
            'Overriding one value must not silently reset the others.'
        );
    }

    public function test_an_impossible_override_falls_back_to_the_compiled_default(): void
    {
        $retention = BackupRetention::fromOverrides(localDays: 0, localMaxBytes: -1);

        $this->assertSame(BackupRetention::DEFAULT_LOCAL_DAYS, $retention->localDays);
        $this->assertSame(BackupRetention::DEFAULT_LOCAL_MAX_BYTES, $retention->localMaxBytes);
    }

    /**
     * The window an ADR-0029 erasure has to outlive is stated here, so the
     * number in the Verzeichnis and the number the code enforces are the same
     * number.
     */
    public function test_the_shipped_windows_are_the_ones_the_documentation_promises(): void
    {
        $this->assertSame(30, BackupRetention::DEFAULT_LOCAL_DAYS);
        $this->assertSame(90, BackupRetention::DEFAULT_REMOTE_DAYS);
        $this->assertSame(1073741824, BackupRetention::DEFAULT_LOCAL_MAX_BYTES, '1 GiB.');
    }
}
