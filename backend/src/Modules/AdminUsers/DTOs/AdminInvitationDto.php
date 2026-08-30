<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\DTOs;

use App\Shared\Utils\DateFormatter;

/**
 * What the admin who issued an invitation is told about it.
 *
 * The URL is in here, and that is a deliberate decision rather than an
 * oversight. The alternative — mail it and say nothing — makes onboarding
 * unusable on an installation whose `mail_config` is not finished or whose host
 * silently drops outbound SMTP, which is a real and common state for a club on
 * shared hosting: the account would exist, the link would sit `pending` in the
 * outbox, and nobody involved would have anything to act on.
 *
 * It is also not a widening. Until this feature, `POST /admin-users` returned a
 * **working password** for the new account to the same caller, on the same
 * screen. This returns something strictly weaker: a credential that expires in
 * a week, works once, and only ever sets a password the caller does not learn.
 *
 * The token itself appears nowhere else — not in the audit entry, not in the
 * application log, and not in any read endpoint. There is no way to ask for it
 * again afterwards, which is what keeps "who could have used this link" a
 * short list.
 */
final readonly class AdminInvitationDto
{
    public function __construct(
        public string $adminUserId,
        public string $email,
        public string $expiresAt,
        /**
         * The absolute link, present only in the response to the request that
         * minted it. Null everywhere the invitation is merely being described.
         */
        public ?string $url = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = [
            'admin_user_id' => $this->adminUserId,
            'email' => $this->email,
            'expires_at' => DateFormatter::toUtcIso($this->expiresAt),
        ];

        if ($this->url !== null) {
            $out['url'] = $this->url;
        }

        return $out;
    }
}
