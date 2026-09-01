<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Domain;

use App\Modules\Registrations\Domain\PosterSecret;
use PHPUnit\Framework\TestCase;

final class PosterSecretTest extends TestCase
{
    public function test_a_minted_secret_is_url_safe_and_long_enough(): void
    {
        $secret = PosterSecret::mint();

        self::assertSame(43, strlen($secret), '32 bytes base64url-encoded');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $secret);
        self::assertNotSame(PosterSecret::mint(), $secret);
    }

    public function test_a_secret_matches_its_own_hash_and_nothing_else(): void
    {
        $secret = PosterSecret::mint();
        $hash = PosterSecret::hash($secret);

        self::assertTrue(PosterSecret::matches($secret, $hash));
        self::assertFalse(PosterSecret::matches(PosterSecret::mint(), $hash));
    }

    /**
     * A club that has never generated a secret must refuse every presented
     * value, including an empty one — and must do it the same way it refuses a
     * wrong guess, so the two are indistinguishable from outside.
     */
    public function test_nothing_matches_when_no_secret_has_been_generated(): void
    {
        self::assertFalse(PosterSecret::matches('anything', null));
        self::assertFalse(PosterSecret::matches('', null));
        self::assertFalse(PosterSecret::matches('anything', ''));
    }

    /**
     * The rule the whole entry point rests on: the secret is in the fragment,
     * so it never reaches an access log. A regression here is silent and
     * permanent — the poster is on a wall for years.
     */
    public function test_the_poster_url_carries_the_secret_in_the_fragment(): void
    {
        $secret = PosterSecret::mint();

        $url = PosterSecret::url('https://club.example/', $secret);

        self::assertSame("https://club.example/register#{$secret}", $url);
        [$beforeFragment] = explode('#', $url, 2);
        self::assertStringNotContainsString($secret, $beforeFragment);
    }
}
