<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * The PHP half of #365, asserted against the real boot sequence.
 *
 * `UtcTest` proves `Utc::apply()` does what it says; this proves `bootstrap.php`
 * actually calls it, and calls it early enough. Those are separate failures: a
 * refactor that drops the call, or moves it below the first `date()`, leaves the
 * unit test green while the deployment goes back to writing local time into
 * columns the API labels "Z".
 */
final class BootstrapTimezoneTest extends HttpTestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    /** Booting on a Europe/Berlin host must still leave the runtime in UTC. */
    public function testBootPinsThePhpRuntimeToUtc(): void
    {
        date_default_timezone_set('Europe/Berlin');

        require __DIR__ . '/../../bootstrap.php';

        $this->assertSame('UTC', date_default_timezone_get());
    }

    /**
     * The property the rest of the codebase relies on: `date('Y-m-d H:i:s')`,
     * used by ~40 repository writes, produces UTC after boot.
     */
    public function testRepositoryStyleTimestampsAreUtcAfterBoot(): void
    {
        date_default_timezone_set('Europe/Berlin');

        require __DIR__ . '/../../bootstrap.php';

        $this->assertSame(gmdate('Y-m-d H:i'), date('Y-m-d H:i'));
    }

    /**
     * The connection the booted container hands to repositories is pinned too —
     * both halves have to hold at once for a stored timestamp to be UTC.
     */
    public function testBootedConnectionUsesAUtcSession(): void
    {
        require __DIR__ . '/../../bootstrap.php';

        $this->assertSame('+00:00', $this->db->query('SELECT @@session.time_zone')->fetchColumn());
    }
}
