<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\Services\CronSecret;
use PHPUnit\Framework\TestCase;

/**
 * Hashing and comparison only. Generation moved out with the panel rotation
 * that used it (#473, #744): `install.php` mints the secret now, and writes it
 * to `config.php` in the clear.
 */
class CronSecretTest extends TestCase
{
    public function test_hash_is_sha256_hex(): void
    {
        $secret = 'a-known-value';

        $this->assertSame(hash('sha256', $secret), CronSecret::hash($secret));
    }

    public function test_verify_accepts_the_matching_plaintext(): void
    {
        $secret = bin2hex(random_bytes(32));

        $this->assertTrue(CronSecret::verify($secret, CronSecret::hash($secret)));
    }

    public function test_verify_rejects_a_wrong_plaintext(): void
    {
        $this->assertFalse(CronSecret::verify('wrong', CronSecret::hash('right')));
    }

    /** A prefix of the real secret must not pass, mirroring the URL-trigger's own guard. */
    public function test_verify_rejects_a_prefix_of_the_secret(): void
    {
        $secret = bin2hex(random_bytes(32));

        $this->assertFalse(CronSecret::verify(substr($secret, 0, 10), CronSecret::hash($secret)));
    }
}
