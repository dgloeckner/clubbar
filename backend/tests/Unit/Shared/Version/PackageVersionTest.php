<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Version;

use App\Shared\Version\PackageVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The no-downgrade guard `upgrade.php` applies before it unpacks a package.
 *
 * The case that made this a class of its own: `1.1.7` was released without
 * its `v`, so an installation of it reports `1.1.7`, and `version_compare()`
 * ranks `v1.1.8` *below* it — a letter sorts before a digit. Production then
 * refused every upgrade with "Downgrade not allowed", installed `1.1.7`
 * against packaged `v1.1.8`.
 */
class PackageVersionTest extends TestCase
{
    /** @return array<string, array{?string, ?string, string}> */
    public static function cases(): array
    {
        return [
            'v-less installed, v-prefixed package'  => ['1.1.7', 'v1.1.8', PackageVersion::UPGRADE],
            'v-prefixed installed, v-less package'  => ['v1.1.6', '1.1.7', PackageVersion::UPGRADE],
            'both prefixed'                         => ['v1.1.7', 'v1.1.8', PackageVersion::UPGRADE],
            'neither prefixed'                      => ['1.1.7', '1.1.8', PackageVersion::UPGRADE],
            'numeric, not lexical'                  => ['v1.1.9', 'v1.1.10', PackageVersion::UPGRADE],
            'same tag, prefix differs'              => ['1.1.7', 'v1.1.7', PackageVersion::SAME],
            'same tag'                              => ['v1.1.8', 'v1.1.8', PackageVersion::SAME],
            'real downgrade across the prefix'      => ['v1.1.8', '1.1.7', PackageVersion::DOWNGRADE],
            'real downgrade'                        => ['v1.2.0', 'v1.1.9', PackageVersion::DOWNGRADE],
            'surrounding whitespace'                => [" v1.1.7\n", 'v1.1.8', PackageVersion::UPGRADE],
            'installed unknown'                     => [null, 'v1.1.8', PackageVersion::UNKNOWN],
            'package unknown'                       => ['v1.1.8', null, PackageVersion::UNKNOWN],
            'dev install'                           => ['dev-abc1234', 'v1.1.8', PackageVersion::UNKNOWN],
            'dev package'                           => ['v1.1.8', 'dev', PackageVersion::UNKNOWN],
        ];
    }

    #[DataProvider('cases')]
    public function testCompare(?string $installed, ?string $package, string $expected): void
    {
        self::assertSame($expected, PackageVersion::compare($installed, $package));
    }
}
