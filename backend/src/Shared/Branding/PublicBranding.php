<?php

declare(strict_types=1);

namespace App\Shared\Branding;

/**
 * Who the club is, for a surface nobody has logged into.
 *
 * The onboarding page (#781) is the club's front door: a stranger reaches it
 * from a QR code on a wall and has to recognise, in the first second, that it
 * belongs to the club whose poster they are standing in front of. An unbranded
 * form asking for a name, a birth date and an IBAN is indistinguishable from a
 * phishing page, and telling somebody to type their IBAN into one is not a
 * thing this project should ever do.
 *
 * ## The same two facts the mail carries
 *
 * {@see \App\Shared\Mail\MailBranding} answers the identical question for the
 * outgoing mail, and the values come from the same two singleton rows — so a
 * club that has branded its mail has branded this page, with nothing further to
 * configure. What travels is deliberately only what is already on the poster in
 * the visitor's hand: the club's name and its mark. No address, no website, no
 * contact — the mail's footer needs those and an anonymous phone does not.
 */
final readonly class PublicBranding
{
    public function __construct(
        /** The instance name, or null when this build could not read one. */
        public ?string $clubName = null,
        /** An `http(s)` or same-origin URL, or null. Never anything else. */
        public ?string $logoUrl = null,
    ) {}

    /**
     * The club's mark, when it is something a browser may be pointed at.
     *
     * `mail_config.logo_url` is written by an admin and read back here into an
     * `img` element on a page served from this origin. Everything but http,
     * https and a same-origin path is dropped rather than sanitised: `data:`
     * and `javascript:` have no business in this field, an admin has never
     * needed to put one there, and a value that arrived by a restore or a
     * direct UPDATE never passed the panel's own validation.
     */
    public static function displayableLogo(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // A protocol-relative `//host/logo.png` is an absolute URL wearing a
        // path's clothes, so it is matched before the path case, not by it.
        if (str_starts_with($value, '//')) {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https' ? $value : null;
    }

    /** @return array{club_name: string|null, logo_url: string|null} */
    public function toArray(): array
    {
        return [
            'club_name' => $this->clubName,
            'logo_url' => $this->logoUrl,
        ];
    }
}
