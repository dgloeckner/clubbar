<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * A release tag, and the two questions ADR-0054 asks of one.
 *
 * *Is this a version at all?* — `dev` and `dev-<sha>` are what a club running
 * from git reports forever, and they are not a ceiling: an unknown version is
 * read the way ADR-0044 reads an unclassified route, as "no", not as "any".
 * Anything that is not `vMAJOR.MINOR.PATCH` (with an optional pre-release
 * suffix, as `v1.0.8-rc.1`) is refused here, once, so that no caller has to
 * decide what a string it does not recognise means.
 *
 * *Which of two is newer?* — compared numerically, field by field, because
 * `v1.0.10` sorts before `v1.0.9` as a string and the terminal updater's whole
 * job is to never move backwards.
 *
 * The same three rules live in the Pi's updater (`clubbar-terminal-update.sh`,
 * `parse_tag`/`newer_tag`) because the script must decide them with no PHP on
 * the machine. `ReleaseVersionTest` and `updater-version.bats` assert the same
 * table of cases so the two implementations cannot drift apart quietly.
 */
final readonly class ReleaseVersion
{
    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        /** Pre-release suffix without its leading hyphen, or '' for a final release. */
        public string $preRelease,
    ) {}

    /**
     * Parse a release tag, or null when the string is not one.
     *
     * Null is the answer for `dev`, `dev-<sha>`, an empty string, a bare
     * `1.0.7` without its `v`, and anything else that does not match — never an
     * exception, because every caller's response to "not a version" is to do
     * nothing rather than to fail.
     */
    public static function parse(?string $tag): ?self
    {
        if ($tag === null) {
            return null;
        }

        $trimmed = trim($tag);
        if ($trimmed === '') {
            return null;
        }

        $matched = preg_match(
            '/^v(\d{1,9})\.(\d{1,9})\.(\d{1,9})(?:-([0-9A-Za-z.-]{1,32}))?$/',
            $trimmed,
            $parts,
        );
        if ($matched !== 1) {
            return null;
        }

        return new self(
            major: (int) $parts[1],
            minor: (int) $parts[2],
            patch: (int) $parts[3],
            preRelease: $parts[4] ?? '',
        );
    }

    /** Whether [$tag] is a release tag this system will act on. */
    public static function isReleaseTag(?string $tag): bool
    {
        return self::parse($tag) !== null;
    }

    /**
     * -1, 0 or 1 for `$a` older than, equal to, or newer than `$b`.
     *
     * A pre-release sorts *before* the final release of the same numbers
     * (`v1.0.8-rc.1` < `v1.0.8`), as SemVer has it. Two pre-releases of the
     * same numbers are compared as plain strings — enough to order `rc.1`
     * before `rc.2`, and this project has never shipped one.
     */
    public static function compare(self $a, self $b): int
    {
        foreach ([[$a->major, $b->major], [$a->minor, $b->minor], [$a->patch, $b->patch]] as [$left, $right]) {
            if ($left !== $right) {
                return $left <=> $right;
            }
        }

        if ($a->preRelease === $b->preRelease) {
            return 0;
        }
        if ($a->preRelease === '') {
            return 1;
        }
        if ($b->preRelease === '') {
            return -1;
        }

        return strcmp($a->preRelease, $b->preRelease) <=> 0;
    }

    /**
     * Compare two tags without parsing them first, or null when either side is
     * not a release tag — "unknown", which is not the same answer as "equal".
     */
    public static function compareTags(?string $a, ?string $b): ?int
    {
        $left = self::parse($a);
        $right = self::parse($b);
        if ($left === null || $right === null) {
            return null;
        }

        return self::compare($left, $right);
    }

    public function __toString(): string
    {
        $base = "v{$this->major}.{$this->minor}.{$this->patch}";

        return $this->preRelease === '' ? $base : "{$base}-{$this->preRelease}";
    }
}
