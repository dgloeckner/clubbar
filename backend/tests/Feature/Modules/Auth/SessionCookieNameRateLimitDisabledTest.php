<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use Tests\Feature\HttpTestCase;

/**
 * `AuthController::mfa()`'s own `session_name()` call (issue #251), reached
 * only when nothing earlier in the request has already started a session.
 *
 * With rate limiting enabled, `ServiceFactory::getMfaRateLimitMiddleware()`'s
 * account resolver runs first and starts the session itself (covered by
 * `SessionCookieNameTest`), so by the time the controller runs the branch is
 * already skipped. `RateLimitMiddleware::process()` returns before touching
 * the resolver at all when `$this->disabled` is true, so disabling it here is
 * what lets the controller be the one to find no session active.
 */
class SessionCookieNameRateLimitDisabledTest extends HttpTestCase
{
    protected function environment(): array
    {
        return array_merge(parent::environment(), [
            'DISABLE_LOGIN_RATE_LIMITING' => 'true',
        ]);
    }

    public function test_mfa_starts_a_session_under_the_configured_cookie_name_when_reached_directly(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'no session must be active before the request');

        $response = $this->request(
            'POST',
            '/api/auth/mfa',
            ['code' => '000000'],
            ['REMOTE_ADDR' => '198.51.100.77'],
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('mfa_session_expired', $this->decode($response)['error']);
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'the controller must have started one itself');
    }
}
