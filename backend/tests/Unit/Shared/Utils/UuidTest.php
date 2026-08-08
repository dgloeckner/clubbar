<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Utils;

use App\Shared\Utils\Uuid;
use PHPUnit\Framework\TestCase;

class UuidTest extends TestCase
{
    public function test_v4_returns_a_hyphenated_36_character_string(): void
    {
        $uuid = Uuid::v4();

        $this->assertSame(36, strlen($uuid));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function test_v4_sets_the_version_and_variant_bits(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $uuid = Uuid::v4();

            // Version nibble: the 15th hex digit must be 4.
            $this->assertSame('4', $uuid[14], "version nibble of {$uuid}");
            // Variant nibble: the 20th hex digit must be one of 8, 9, a, b.
            $this->assertContains($uuid[19], ['8', '9', 'a', 'b'], "variant nibble of {$uuid}");
        }
    }

    public function test_v4_does_not_repeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $seen[Uuid::v4()] = true;
        }

        $this->assertCount(1000, $seen);
    }
}
