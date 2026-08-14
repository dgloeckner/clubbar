<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Repository;

use App\Shared\Repository\SafeQuery;
use PHPUnit\Framework\TestCase;

/**
 * `escapeLike` has one job: make a search term mean itself.
 *
 * It used to escape `%` and `_` before the backslash, so `str_replace` — which
 * applies its pairs in order over the previous result — escaped the very
 * backslashes it had just introduced. The pattern that came out matched
 * nothing, and the member, journal and audit-log searches quietly returned no
 * rows for any term containing an underscore or a percent sign.
 *
 * These tests are written against the pattern LIKE actually receives, because
 * that is where the bug lived; `escapeLikeMatchesInMariaDb` in the repository
 * feature tests pins the same terms against a real database.
 */
class SafeQueryTest extends TestCase
{
    public function test_an_underscore_is_escaped_exactly_once(): void
    {
        // Not `Deckel\\_1`: two backslashes are an escaped backslash, and the
        // `_` after them is a wildcard again — the pattern then looks for a
        // literal backslash the term never contained.
        $this->assertSame('Deckel\\_1', SafeQuery::escapeLike('Deckel_1'));
    }

    public function test_a_percent_sign_is_escaped_exactly_once(): void
    {
        $this->assertSame('100\\%', SafeQuery::escapeLike('100%'));
    }

    public function test_a_backslash_the_user_typed_is_escaped(): void
    {
        // The one term the ordering exists for: the user's own backslash must
        // be doubled, and doubled only once.
        $this->assertSame('a\\\\b', SafeQuery::escapeLike('a\\b'));
    }

    public function test_a_backslash_before_a_wildcard_stays_literal(): void
    {
        // `\_` typed by a user is a backslash followed by an underscore, not
        // an escape sequence they authored.
        $this->assertSame('a\\\\\\_b', SafeQuery::escapeLike('a\\_b'));
    }

    public function test_a_term_with_no_wildcards_is_left_alone(): void
    {
        $this->assertSame('Muller', SafeQuery::escapeLike('Muller'));
    }

    public function test_every_wildcard_in_a_mixed_term_is_escaped(): void
    {
        $this->assertSame('a\\_b\\%c', SafeQuery::escapeLike('a_b%c'));
    }

    public function test_an_empty_term_stays_empty(): void
    {
        $this->assertSame('', SafeQuery::escapeLike(''));
    }
}
