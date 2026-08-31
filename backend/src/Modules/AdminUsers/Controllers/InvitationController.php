<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Controllers;

use App\Modules\AdminUsers\Services\AdminInvitationService;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Shared\Http\JsonResponder;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The public half of admin onboarding (migration 058): the two requests a
 * browser makes while somebody follows an invitation link.
 *
 * **Unauthenticated by necessity.** The whole point is that the invitee has no
 * account yet — no session to carry, no password to prove, no CSRF token to
 * hold. What stands in for authentication is the token itself, which is 256
 * bits of entropy, single use, and dead after
 * {@see \App\Modules\AdminUsers\Domain\InvitationLink::TTL_DAYS} days.
 *
 * **The token travels in the request body, never in the path.** Both routes are
 * therefore POSTs with constant URLs, including the one that only reads. A
 * request line is written verbatim to every access log in front of the
 * installation — in the shipped package, twice per request, by php-fpm and by
 * httpd — so a token in the path would put a live credential into a file that
 * outlives it and that anybody with hosting-panel access can read. A request
 * body is written to none of them. This is the same reason `POST
 * /api/auth/login` does not take the password in its URL, and it is why `GET
 * /api/invitations/{token}` had to become `POST /api/invitations/lookup`
 * despite reading nothing: the safe shape wins over the RESTful one when the
 * URL is the leak.
 *
 * Both routes sit behind the login rate limiter on its IP dimension, and every
 * refused token is written to `login_attempts` — so guessing tokens costs the
 * same budget as guessing passwords, from the same address, rather than being
 * the one unmetered credential surface in the system.
 *
 * Neither route is behind `CsrfMiddleware`. There is no session cookie for a
 * CSRF token to protect, exactly as for `POST /api/auth/login`, and the only
 * thing a forged request could achieve is spending a token the forger already
 * had to know.
 */
class InvitationController
{
    use JsonResponder;

    public function __construct(
        private AdminInvitationService $invitationService,
        private Validator $validator,
        private LoginAttemptsRepository $loginAttempts,
    ) {}

    /**
     * POST /api/invitations/lookup
     *
     * What the accept page renders its greeting from. Answers a name, an
     * address, a locale and the account's own roles, and nothing else — a token
     * proves its holder can read one mailbox, which is not a reason to tell
     * them about the club's other accounts.
     *
     * A POST that changes nothing, for the reason in the class docblock: the
     * token must not be in the URL, and a GET cannot reliably carry a body.
     */
    public function lookup(Request $request, Response $response): Response
    {
        try {
            $invitee = $this->invitationService->describe($this->token($request));
        } catch (\Throwable $e) {
            $this->recordRefusal($request);
            throw $e;
        }

        return $this->json($response, ['invitation' => $invitee]);
    }

    /**
     * POST /api/invitations/accept
     *
     * Sets the account's first password and spends the link. The response
     * carries the address to sign in with so the panel can send the invitee
     * straight to the login form with it filled in — and from there the
     * ordinary first-login path takes over, which is what puts them through
     * Authenticator enrolment. **No session is minted here**; a mail link must
     * not be able to produce one.
     *
     * The password rules are `PATCH /api/auth/change-password`'s, character for
     * character. Two different standards for the same secret is how one of them
     * ends up being the weaker one nobody noticed.
     */
    public function accept(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'password' => ['required', 'string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/'],
            'password_confirmation' => ['required', 'same:password'],
        ])) {
            // Not recorded as a failed attempt: a password that is too short is
            // the invitee mistyping their own new secret, not somebody probing
            // for a token. Counting it would lock a legitimate new admin out of
            // their own onboarding for fifteen minutes.
            return $this->validationFailed($response, $this->validator->errors());
        }

        try {
            $result = $this->invitationService->accept($this->token($request), (string) $body['password']);
        } catch (\Throwable $e) {
            $this->recordRefusal($request);
            throw $e;
        }

        return $this->json($response, [
            'email' => $result['email'],
            'message' => 'Password set. Sign in to finish setting up two-factor authentication.',
        ]);
    }

    /**
     * The submitted token, read from the body and from nowhere else.
     *
     * A missing one is handed to the service as an empty string rather than
     * refused separately, so it takes the same path as a wrong one and gets the
     * same answer: an absent token and an invented token are both simply not a
     * token, and telling them apart is a distinction only somebody probing the
     * endpoint would have a use for.
     */
    private function token(Request $request): string
    {
        $body = $request->getParsedBody();

        return is_array($body) ? (string) ($body['token'] ?? '') : '';
    }

    /**
     * Count a refused token against the caller's IP.
     *
     * There is no account dimension to count against, deliberately: resolving
     * one would mean telling the limiter which account a bad token *would have*
     * named, and a token that does not resolve names none. The IP budget is
     * shared with `POST /api/auth/login`, which is the right shape — both are
     * somebody at one address trying credentials that do not work.
     */
    private function recordRefusal(Request $request): void
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->loginAttempts->record($ip, null);
    }
}
