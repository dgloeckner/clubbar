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

    /**
     * The poster's URL does not change (#823).
     *
     * Every printed one is on a wall for years and cannot be reissued, so the
     * no-address call has to keep producing byte-for-byte what it always did.
     */
    public function test_the_poster_url_is_unchanged_when_no_address_is_given(): void
    {
        $secret = PosterSecret::mint();

        self::assertSame(
            PosterSecret::url('https://club.example', $secret),
            PosterSecret::url('https://club.example', $secret, null),
        );
        self::assertSame(
            PosterSecret::url('https://club.example', $secret),
            PosterSecret::url('https://club.example', $secret, '   '),
        );
    }

    /**
     * The rule the prefill rests on, and the one a query string would break:
     * the address is personal data, so it must not reach a request line either.
     */
    public function test_the_mailed_url_carries_the_address_in_the_fragment(): void
    {
        $secret = PosterSecret::mint();

        $url = PosterSecret::url('https://club.example', $secret, 'neu@example.org');

        self::assertSame("https://club.example/register#{$secret}&email=neu%40example.org", $url);

        // Nothing before the `#` is sent by a browser; everything before it is.
        [$beforeFragment] = explode('#', $url, 2);
        self::assertStringNotContainsString('neu', $beforeFragment);
        self::assertStringNotContainsString('?', $url);
    }

    /**
     * `rawurlencode`, not `urlencode`: the latter spells a space `+`, and a `+`
     * is a legal character in a local part — `decodeURIComponent` would hand
     * back the wrong address for one of the two.
     */
    public function test_an_address_with_a_plus_survives_the_round_trip(): void
    {
        $url = PosterSecret::url('https://club.example', PosterSecret::mint(), 'lena+verein@example.org');

        self::assertStringContainsString('email=lena%2Bverein%40example.org', $url);
        self::assertStringNotContainsString('lena+verein', $url);
    }

    /**
     * The separator can never collide with the secret, which is what lets the
     * page take everything before the first `&` as the secret.
     */
    public function test_a_minted_secret_can_never_contain_the_separator(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::assertStringNotContainsString('&', PosterSecret::mint());
            self::assertStringNotContainsString('=', PosterSecret::mint());
        }
    }
}
