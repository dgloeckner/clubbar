<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Domain;

/**
 * The shape of an invitation token and of the URL that carries it
 * (migration 058).
 *
 * Kept out of the service because three different places have to agree on it
 * and none of them should be the authority: the service mints tokens, the mail
 * builder renders links, and the accept endpoint hashes what a browser sent
 * back. A second opinion about the encoding anywhere in that chain is a link
 * that resolves to nothing, discovered by whoever was being onboarded.
 */
final class InvitationLink
{
    /**
     * 32 bytes of entropy, base64url-encoded to 43 characters.
     *
     * Sized like a session identifier rather than like a password, because
     * that is what it is: a bearer credential nobody memorises, typed by
     * nothing and read only by a browser following a link.
     */
    private const TOKEN_BYTES = 32;

    /**
     * The SPA route the link points at.
     *
     * A client-side path, served by the same front controller as everything
     * else that is not `/api/` (`package/index.php`), so no server route has to
     * exist for it and no build step has to know about it.
     *
     * The token is **not** part of it — see {@see url()}.
     */
    public const PATH = '/invite';

    /** How long a link works. */
    public const TTL_DAYS = 7;

    public static function mintToken(): string
    {
        // Base64url: the token travels in a URL fragment and in a JSON body,
        // and `+` and `/` do not survive the former intact. `=` is stripped for
        // the same reason.
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    /**
     * The value stored and looked up by.
     *
     * SHA-256 rather than a password hash: a 256-bit random token has no
     * guessable structure, so the work factor bcrypt buys against dictionary
     * attacks protects nothing here — and a slow hash on the lookup key would
     * mean either scanning every row or storing the token in the clear to find
     * one. The same reasoning `terminals.api_token_hash` already follows.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The absolute URL the invitee opens.
     *
     * **The token is in the fragment, never in the path.** A URL fragment is
     * the one part of a URL a browser does not put on the wire: it is stripped
     * before the request is made, so it reaches no access log, no proxy log, no
     * `Referer` header and no error page. Everything left of the `#` is a
     * constant.
     *
     * A path segment would have been the obvious shape and is the wrong one. A
     * bearer credential written into `GET /invite/<token>` is recorded verbatim
     * by every web server in front of the installation — twice over in the
     * shipped package, by php-fpm and by httpd — which hands a working
     * invitation to anybody who can read a log file. That is precisely the
     * handover this feature exists to abolish, reintroduced one layer down.
     *
     * The residue is the invitee's own browser history, on the invitee's own
     * machine, for a single-use token that dies in
     * {@see TTL_DAYS} days. The fragment is deliberately *not* stripped after
     * the page reads it: doing so would break a reload for somebody who has
     * legitimately opened their own invitation.
     *
     * @param string $appUrl The installation's public base URL (`APP_URL` /
     *        `app.url`). The SPA and the API are one origin in the shipped
     *        package, so this is the panel's address as well as the API's.
     */
    public static function url(string $appUrl, string $token): string
    {
        return rtrim($appUrl, '/') . self::PATH . '#' . $token;
    }

    /** When a link minted now stops working. */
    public static function expiresAt(?int $now = null): string
    {
        return date('Y-m-d H:i:s', ($now ?? time()) + self::TTL_DAYS * 86400);
    }

    /**
     * Whether a submitted token could possibly be one of ours.
     *
     * A cheap shape check in front of the database, so a body full of
     * punctuation never becomes a query. It says nothing about validity — that
     * is the service's answer, from the row.
     */
    public static function looksLikeToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token) === 1;
    }
}
