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
 * The same rules live in the Pi's updater (`clubbar-update.sh`,
 * `is_release_tag`/`compare_tags`) because the script must decide them with no
 * PHP on the machine. `ReleaseVersionTest` and
 * `terminal-frontend/scripts/test/updater-version.sh` assert the same table of
 * cases, and CI runs both, so the two implementations cannot drift apart
 * quietly.
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
     * (`v1.0.8-rc.1` < `v1.0.8`), as SemVer has it.
     */
    public static function compare(self $a, self $b): int
    {
        foreach ([[$a->major, $b->major], [$a->minor, $b->minor], [$a->patch, $b->patch]] as [$left, $right]) {
            if ($left !== $right) {
                return $left <=> $right;
            }
        }

        return self::comparePreRelease($a->preRelease, $b->preRelease);
    }

    /**
     * SemVer §11.4 on two pre-release suffixes, with '' meaning "not a
     * pre-release at all".
     *
     * This was a plain `strcmp` while the only thing it had to order was
     * `rc.1` before `rc.2`, and that was wrong in a way worth spelling out:
     * `rc.9` sorts *after* `rc.10` as a string, so a terminal on `-rc.9`
     * offered `-rc.10` would read it as a downgrade, log "Never downgrading"
     * and never move again. A refusal that reports itself as prudence is the
     * worst failure this design can have, so the rule is the real one even
     * though the repository's own convention — a constant `-beta` suffix with
     * the counter in the patch field — has never reached it.
     */
    private static function comparePreRelease(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        // A final release outranks any pre-release of the same numbers.
        if ($a === '') {
            return 1;
        }
        if ($b === '') {
            return -1;
        }

        $left = explode('.', $a);
        $right = explode('.', $b);

        for ($i = 0, $count = max(count($left), count($right)); $i < $count; $i++) {
            // All preceding identifiers equal: the longer set wins.
            if (!isset($left[$i])) {
                return -1;
            }
            if (!isset($right[$i])) {
                return 1;
            }

            $l = $left[$i];
            $r = $right[$i];
            $lNumeric = $l !== '' && ctype_digit($l);
            $rNumeric = $r !== '' && ctype_digit($r);

            if ($lNumeric && $rNumeric) {
                $cmp = self::compareNumericIdentifiers($l, $r);
                if ($cmp !== 0) {
                    return $cmp;
                }
                continue;
            }

            // A numeric identifier always ranks below an alphanumeric one.
            if ($lNumeric !== $rNumeric) {
                return $lNumeric ? -1 : 1;
            }

            $cmp = strcmp($l, $r);
            if ($cmp !== 0) {
                return $cmp <=> 0;
            }
        }

        return 0;
    }

    /**
     * Two all-digit identifiers, by length and then lexically.
     *
     * Not by casting to int: the suffix may be up to 32 characters, and a
     * 20-digit identifier cast to int would silently saturate and compare
     * equal to another. Length-then-lexical is exact at any width.
     */
    private static function compareNumericIdentifiers(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        $a = $a === '' ? '0' : $a;
        $b = $b === '' ? '0' : $b;

        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
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
