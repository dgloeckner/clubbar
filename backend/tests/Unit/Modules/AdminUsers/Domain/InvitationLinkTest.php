<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Domain;

use App\Modules\AdminUsers\Domain\InvitationLink;
use PHPUnit\Framework\TestCase;

/**
 * The shape of an invitation token and the URL carrying it (migration 058).
 *
 * Three parties have to agree on this — the service that mints, the mail
 * builder that renders, and the endpoint that hashes what a browser sent back —
 * and a disagreement anywhere in that chain is a link that resolves to nothing,
 * discovered by somebody who has no account yet and nobody to ask.
 */
class InvitationLinkTest extends TestCase
{
    /**
     * The token has to survive being a path segment.
     *
     * Plain base64 would not: `+` and `/` do not travel through a URL path
     * intact, and `=` is padding a router is free to mangle. A token that comes
     * back altered hashes to something else and is refused as unknown — a
     * failure that would look like an expired link rather than an encoding bug.
     */
    public function test_a_token_is_url_safe_and_long_enough_to_be_unguessable(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $token = InvitationLink::mintToken();

            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
            // 32 bytes, base64url, unpadded.
            $this->assertSame(43, strlen($token));
            $this->assertTrue(InvitationLink::looksLikeToken($token));
        }
    }

    public function test_two_tokens_are_never_the_same(): void
    {
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $tokens[] = InvitationLink::mintToken();
        }

        $this->assertCount(100, array_unique($tokens));
    }

    public function test_the_hash_is_stable_and_hides_the_token(): void
    {
        $token = InvitationLink::mintToken();
        $hash = InvitationLink::hash($token);

        $this->assertSame($hash, InvitationLink::hash($token), 'the same token must always find the same row');
        $this->assertSame(64, strlen($hash));
        $this->assertStringNotContainsString($token, $hash);
        $this->assertNotSame($hash, InvitationLink::hash(InvitationLink::mintToken()));
    }

    /**
     * The shape check in front of the database. It says nothing about validity
     * — that comes from the row — but a path full of punctuation should never
     * become a query.
     */
    public function test_a_malformed_token_is_recognisable_without_a_lookup(): void
    {
        foreach (['', 'short', '../../etc/passwd', 'has spaces', "tok\nen", str_repeat('a', 200)] as $bad) {
            $this->assertFalse(InvitationLink::looksLikeToken($bad), var_export($bad, true) . ' must not look like a token');
        }
    }

    /**
     * One slash between the origin and the path, whether or not the configured
     * base URL ends in one. Two would give a link that 404s on some servers and
     * works on others, which is the worst of the two outcomes.
     */
    public function test_the_url_joins_the_base_and_the_token_exactly_once(): void
    {
        $token = 'AbC-123_xyz';

        $this->assertSame(
            'https://club.example.org/invite#AbC-123_xyz',
            InvitationLink::url('https://club.example.org', $token),
        );
        $this->assertSame(
            'https://club.example.org/invite#AbC-123_xyz',
            InvitationLink::url('https://club.example.org/', $token),
        );
    }

    /**
     * The reason the link is shaped the way it is: a **fragment** is the one
     * part of a URL a browser never puts on the wire, so a token carried there
     * reaches no access log, no proxy log and no `Referer` header.
     *
     * A path segment — the obvious shape, and the one this started as — is
     * written verbatim into every web server log in front of the installation,
     * twice per request in the shipped package. That would hand a working
     * invitation to anybody who can read a log file, which is the credential
     * handover this whole feature exists to abolish.
     *
     * Hence the assertion on everything *left* of the `#`: it must be a
     * constant, whatever the token is.
     */
    public function test_the_token_is_in_the_fragment_and_never_in_the_path(): void
    {
        $url = InvitationLink::url('https://club.example.org', InvitationLink::mintToken());

        $parts = parse_url($url);

        $this->assertSame('/invite', $parts['path'] ?? null, 'the path must carry no token');
        $this->assertArrayNotHasKey('query', $parts, 'a query string is logged as often as a path is');
        $this->assertNotEmpty($parts['fragment'] ?? '', 'the token must be the fragment');
        $this->assertTrue(InvitationLink::looksLikeToken($parts['fragment']));
    }

    public function test_a_link_expires_seven_days_after_it_is_minted(): void
    {
        $now = mktime(12, 0, 0, 3, 1, 2026);

        $this->assertSame(
            date('Y-m-d H:i:s', $now + 7 * 86400),
            InvitationLink::expiresAt($now),
        );
    }
}
