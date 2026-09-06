<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Version;

use App\Shared\Version\ReleaseVersion;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0054 turns "is this string a version, and which of two is newer" into a
 * correctness question: the first answer decides whether a terminal ever
 * updates, the second decides whether it can move backwards.
 *
 * The same table is asserted against the Pi's shell implementation in
 * `terminal-frontend/scripts/test/updater-version.sh`. When a case is added
 * here, add it there.
 */
class ReleaseVersionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function releaseTags(): array
    {
        return [
            'plain release' => ['v1.0.7'],
            'zero major' => ['v0.1.18'],
            'double-digit patch' => ['v1.0.10'],
            'pre-release suffix' => ['v1.0.8-rc.1'],
        ];
    }

    /**
     * @dataProvider releaseTags
     */
    public function test_a_release_tag_parses(string $tag): void
    {
        $this->assertTrue(ReleaseVersion::isReleaseTag($tag));
        $this->assertSame($tag, (string) ReleaseVersion::parse($tag));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function nonReleaseTags(): array
    {
        return [
            // The two a club deployed from git reports forever. Fail-closed:
            // an unknown version is not an infinite ceiling.
            'dev' => ['dev'],
            'dev with sha' => ['dev-4f2a9c1'],
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'no v prefix' => ['1.0.7'],
            'two components' => ['v1.0'],
            'four components' => ['v1.0.7.1'],
            'branch name' => ['main'],
            'latest' => ['latest'],
            // Anything that would end up in a path or a URL unescaped.
            'path traversal' => ['v1.0.7/../../etc'],
            'shell metacharacters' => ['v1.0.7; rm -rf /'],
        ];
    }

    /**
     * @dataProvider nonReleaseTags
     */
    public function test_anything_that_is_not_a_release_tag_is_refused(?string $tag): void
    {
        $this->assertNull(ReleaseVersion::parse($tag));
        $this->assertFalse(ReleaseVersion::isReleaseTag($tag));
    }

    public function test_versions_compare_numerically_not_as_strings(): void
    {
        // The case a string comparison gets wrong, and the reason this class
        // exists: 'v1.0.10' < 'v1.0.9' lexically.
        $this->assertSame(1, ReleaseVersion::compareTags('v1.0.10', 'v1.0.9'));
        $this->assertSame(-1, ReleaseVersion::compareTags('v1.0.9', 'v1.0.10'));
        $this->assertSame(1, ReleaseVersion::compareTags('v1.0.0', 'v0.9.99'));
        $this->assertSame(1, ReleaseVersion::compareTags('v1.1.0', 'v1.0.99'));
        $this->assertSame(0, ReleaseVersion::compareTags('v1.0.7', 'v1.0.7'));
        // A leading zero is a number, not an octal literal — the shell
        // implementation has to say so explicitly, so the case is pinned here
        // too rather than left to differ silently.
        $this->assertSame(0, ReleaseVersion::compareTags('v1.0.8', 'v1.0.08'));
        $this->assertSame(0, ReleaseVersion::compareTags('v1.0.10', 'v1.0.010'));
    }

    public function test_a_pre_release_sorts_before_the_release_it_precedes(): void
    {
        $this->assertSame(-1, ReleaseVersion::compareTags('v1.0.8-rc.1', 'v1.0.8'));
        $this->assertSame(1, ReleaseVersion::compareTags('v1.0.8', 'v1.0.8-rc.1'));
        $this->assertSame(-1, ReleaseVersion::compareTags('v1.0.8-rc.1', 'v1.0.8-rc.2'));
    }

    /**
     * The case a string comparison gets wrong, and the one that would matter: a
     * terminal on `-rc.9` offered `-rc.10` would read it as a downgrade, log
     * "Never downgrading" and never move again. A refusal that reports itself
     * as prudence is the worst failure this design can have.
     */
    public function test_numeric_pre_release_identifiers_compare_numerically(): void
    {
        $this->assertSame(-1, ReleaseVersion::compareTags('v1.0.8-rc.9', 'v1.0.8-rc.10'));
        $this->assertSame(1, ReleaseVersion::compareTags('v1.0.8-rc.10', 'v1.0.8-rc.9'));
        // Leading zeros are still the same number.
        $this->assertSame(0, ReleaseVersion::compareTags('v1.0.8-rc.9', 'v1.0.8-rc.09'));
        // Wider than any integer, and still ordered exactly.
        $this->assertSame(
            -1,
            ReleaseVersion::compareTags('v1.0.8-rc.99999999999999999999', 'v1.0.8-rc.100000000000000000000'),
        );
    }

    public function test_a_numeric_identifier_ranks_below_an_alphanumeric_one(): void
    {
        $this->assertSame(-1, ReleaseVersion::compareTags('v1.0.8-1', 'v1.0.8-alpha'));
        $this->assertSame(1, ReleaseVersion::compareTags('v1.0.8-alpha', 'v1.0.8-1'));
    }

    public function test_the_longer_set_of_identifiers_wins_when_the_rest_are_equal(): void
    {
        $this->assertSame(-1, ReleaseVersion::compareTags('v1.0.8-alpha', 'v1.0.8-alpha.1'));
        $this->assertSame(1, ReleaseVersion::compareTags('v1.0.8-alpha.1', 'v1.0.8-alpha'));
    }

    /**
     * The worked example from SemVer §11.4, asserted as one chain. The shell
     * implementation asserts the same chain in `updater-version.sh`.
     */
    public function test_the_specifications_own_worked_example(): void
    {
        $ordered = [
            'v1.0.0-alpha',
            'v1.0.0-alpha.1',
            'v1.0.0-alpha.beta',
            'v1.0.0-beta',
            'v1.0.0-beta.2',
            'v1.0.0-beta.11',
            'v1.0.0-rc.1',
            'v1.0.0',
        ];

        for ($i = 0; $i < count($ordered) - 1; $i++) {
            $this->assertSame(
                -1,
                ReleaseVersion::compareTags($ordered[$i], $ordered[$i + 1]),
                "{$ordered[$i]} should sort before {$ordered[$i + 1]}",
            );
        }
    }

    /**
     * This repository's own convention, which is why none of the above has ever
     * been reached: a constant `-beta` suffix with the counter in the patch
     * field, which orders correctly under a string comparison too.
     */
    public function test_this_projects_own_tags_order_correctly(): void
    {
        $this->assertSame(-1, ReleaseVersion::compareTags('v0.1.18-beta', 'v0.1.19-beta'));
        $this->assertSame(1, ReleaseVersion::compareTags('v1.0.0', 'v0.1.19-beta'));
    }

    public function test_comparing_against_a_non_version_is_unknown_not_equal(): void
    {
        // `dev` must never read as "same as the backend", which would be a
        // silent decision to do nothing forever *and* to report health.
        $this->assertNull(ReleaseVersion::compareTags('dev', 'v1.0.7'));
        $this->assertNull(ReleaseVersion::compareTags('v1.0.7', 'dev'));
        $this->assertNull(ReleaseVersion::compareTags('dev', 'dev'));
        $this->assertNull(ReleaseVersion::compareTags(null, 'v1.0.7'));
    }
}
