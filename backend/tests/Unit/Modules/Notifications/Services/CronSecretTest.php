<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\Services\CronSecret;
use PHPUnit\Framework\TestCase;

class CronSecretTest extends TestCase
{
    public function test_generates_256_bits_of_hex(): void
    {
        $secret = CronSecret::generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function test_two_generated_secrets_do_not_collide(): void
    {
        $this->assertNotSame(CronSecret::generate(), CronSecret::generate());
    }

    public function test_hash_is_sha256_hex(): void
    {
        $secret = 'a-known-value';

        $this->assertSame(hash('sha256', $secret), CronSecret::hash($secret));
    }

    public function test_verify_accepts_the_matching_plaintext(): void
    {
        $secret = CronSecret::generate();

        $this->assertTrue(CronSecret::verify($secret, CronSecret::hash($secret)));
    }

    public function test_verify_rejects_a_wrong_plaintext(): void
    {
        $this->assertFalse(CronSecret::verify('wrong', CronSecret::hash('right')));
    }

    /** A prefix of the real secret must not pass, mirroring the URL-trigger's own guard. */
    public function test_verify_rejects_a_prefix_of_the_secret(): void
    {
        $secret = CronSecret::generate();

        $this->assertFalse(CronSecret::verify(substr($secret, 0, 10), CronSecret::hash($secret)));
    }
}
