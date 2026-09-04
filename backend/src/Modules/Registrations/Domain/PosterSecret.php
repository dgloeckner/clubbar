<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain;

/**
 * The shape of the secret a QR poster carries, and of the URL that carries it
 * (migration 059, ADR-0052 decision 1).
 *
 * Kept out of the service for the reason {@see \App\Modules\AdminUsers\Domain\InvitationLink}
 * is: three places have to agree on the encoding — whoever mints it, whoever
 * prints the poster, and whoever hashes what a phone sent back — and a second
 * opinion anywhere in that chain is a poster that resolves to nothing, found by
 * somebody standing in the clubhouse.
 */
final class PosterSecret
{
    /**
     * 32 bytes of entropy, base64url-encoded to 43 characters.
     *
     * The same size as an invitation token and for the same reason: a bearer
     * credential nobody memorises. It is longer-lived than one, though — this
     * is printed on paper and pinned to a wall, where it may still be in 2028 —
     * which is why it grants only the ability to *submit* and never to read.
     */
    private const SECRET_BYTES = 32;

    /** The page the QR code opens. A constant; the secret is not part of it. */
    public const PATH = '/register';

    public static function mint(): string
    {
        // Base64url: the secret travels in a URL fragment and in a JSON body,
        // and `+` and `/` survive neither intact. `=` is stripped likewise.
        return rtrim(strtr(base64_encode(random_bytes(self::SECRET_BYTES)), '+/', '-_'), '=');
    }

    /**
     * The value stored and compared against.
     *
     * SHA-256, not a password hash: 256 bits of uniform randomness has no
     * guessable structure, so the work factor bcrypt buys against dictionaries
     * protects nothing — and a slow hash on the lookup key would mean either
     * scanning rows or keeping the secret readable. `terminals.api_token_hash`
     * and `admin_user_invitations.token_hash` already settled this.
     */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Constant-time comparison of a presented secret against the stored hash.
     *
     * `hash_equals` rather than `===` because the comparison is against a
     * value an attacker supplies and can vary a byte at a time. The hashes are
     * fixed-length hex, so there is no length leak to worry about beyond it.
     */
    public static function matches(string $presented, ?string $storedHash): bool
    {
        if ($storedHash === null || $storedHash === '') {
            // No secret has ever been generated. Nothing can match, and the
            // caller must not be able to tell that apart from a wrong guess.
            return false;
        }

        return hash_equals($storedHash, self::hash($presented));
    }

    /**
     * The query-string key the recipient's own address travels under, inside
     * the fragment (#823).
     *
     * A key rather than a second bare segment because the fragment now has two
     * readers to keep in agreement — this class and `register.js` — and a
     * positional format is the one that breaks silently the day a third value
     * is added.
     */
    public const EMAIL_PARAM = 'email';

    /**
     * The absolute URL the QR code encodes, and the one the Anmeldelink mails.
     *
     * **The secret is in the fragment, never in the path**, which is the whole
     * reason this method exists rather than a string concatenation at the call
     * site. A fragment is the one part of a URL a browser never puts on the
     * wire: it is stripped before the request, so it reaches no access log, no
     * proxy, no `Referer` and no error page.
     *
     * A path would have been the obvious shape and is the wrong one, more so
     * here than for an invitation. A poster is a credential with a life
     * measured in years; written into `GET /register/<secret>` it would be
     * recorded verbatim by every web server in front of the installation —
     * twice per request in the shipped package — for every one of those years.
     *
     * ## The recipient's own address rides in the same fragment (#823)
     *
     * The Anmeldelink is sent *to* an address a Kassenwart typed, and the first
     * thing the form then asks for is that same address — from the one reader
     * who did not need to be asked, on a phone, from memory. `$email` fills it
     * in for them.
     *
     * It goes **after the `#`, for the reason the secret does**: a query string
     * is part of the request line, so `?email=` would write a prospective
     * member's address into every access log in front of the installation. The
     * poster flow refuses that for a credential; refusing it for personal data
     * is the same rule (ADR-0052 decision 1, ADR-0018's privacy stance).
     *
     * The poster passes nothing here and its URL is byte-for-byte what it
     * always was — a wall reaches nobody in particular, so there is nobody to
     * fill in.
     *
     * @param ?string $email Prefills the form's E-Mail field. Encoded, never
     *        trusted: the page validates it before using it, and the server
     *        validates whatever is finally submitted regardless.
     */
    public static function url(string $appUrl, string $secret, ?string $email = null): string
    {
        $url = rtrim($appUrl, '/') . self::PATH . '#' . $secret;

        $email = trim((string) $email);
        if ($email === '') {
            return $url;
        }

        // rawurlencode, not urlencode: the latter spells a space `+`, which
        // `decodeURIComponent` hands back as `+` rather than as a space — and a
        // `+` is legal in a local part, so the two must not be confused.
        return $url . '&' . self::EMAIL_PARAM . '=' . rawurlencode($email);
    }
}
