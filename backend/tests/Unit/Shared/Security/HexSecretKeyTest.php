<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Security\HexSecretKey;
use PHPUnit\Framework\TestCase;

class HexSecretKeyTest extends TestCase
{
    private const VALID_KEY = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const PUBLISHED_KEY = '0000000000000000000000000000000000000000000000000000000000000001';

    public function testDecodeReturnsThirtyTwoRawBytes(): void
    {
        $raw = HexSecretKey::decode(self::VALID_KEY, 'SOME_KEY');

        $this->assertSame(32, strlen($raw));
        $this->assertSame(hex2bin(self::VALID_KEY), $raw);
    }

    /** @dataProvider unusableKeys */
    public function testDecodeRejectsWrongLengthOrNonHex(string $keyHex): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOME_KEY');
        HexSecretKey::decode($keyHex, 'SOME_KEY');
    }

    /** @return array<string, array{string}> */
    public static function unusableKeys(): array
    {
        return [
            'empty' => [''],
            'half length' => [str_repeat('a', 32)],
            'one hex char short' => [str_repeat('a', 63)],
            'one hex char long' => [str_repeat('a', 65)],
            'non hex' => [str_repeat('z', 64)],
        ];
    }

    public function testRejectIfPublishedThrowsOutsideDevelopmentEnvs(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('do not use in production');
        HexSecretKey::rejectIfPublished(
            self::PUBLISHED_KEY,
            [self::PUBLISHED_KEY],
            'production',
            'do not use in production',
        );
    }

    public function testRejectIfPublishedThrowsWhenAppEnvIsUnset(): void
    {
        $this->expectException(\RuntimeException::class);
        HexSecretKey::rejectIfPublished(self::PUBLISHED_KEY, [self::PUBLISHED_KEY], '', 'blocked');
    }

    /** @dataProvider developmentEnvironments */
    public function testRejectIfPublishedAllowsDevelopmentEnvs(string $appEnv): void
    {
        HexSecretKey::rejectIfPublished(self::PUBLISHED_KEY, [self::PUBLISHED_KEY], $appEnv, 'blocked');
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function developmentEnvironments(): array
    {
        return [
            'local' => ['local'],
            'test' => ['test'],
            'development' => ['development'],
            'Local, capitalised' => ['Local'],
        ];
    }

    public function testRejectIfPublishedAllowsANonPublishedKeyInProduction(): void
    {
        HexSecretKey::rejectIfPublished(self::VALID_KEY, [self::PUBLISHED_KEY], 'production', 'blocked');
        $this->addToAssertionCount(1);
    }

    public function testRejectIfPublishedSupportsACustomExceptionClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HexSecretKey::rejectIfPublished(
            self::PUBLISHED_KEY,
            [self::PUBLISHED_KEY],
            'production',
            'blocked',
            \InvalidArgumentException::class,
        );
    }
}
