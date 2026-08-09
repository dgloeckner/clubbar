<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Tests\Feature\HttpTestCase;

/**
 * `X-Content-Type-Options: nosniff` on every response, from the real stack.
 *
 * A unit test on the middleware would only prove the class sets a header. What
 * this file is actually for is the wiring: the header has to survive middleware
 * ordering, and in particular has to be present on the responses ErrorHandler
 * builds itself — those are returned from inside it and never touch middleware
 * registered further in (#107).
 */
class SecurityHeadersTest extends HttpTestCase
{
    public function test_a_successful_response_is_marked_nosniff(): void
    {
        $response = $this->request('GET', '/api/health');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function test_an_error_response_from_the_error_handler_is_marked_nosniff(): void
    {
        $response = $this->request('GET', '/api/no-such-route');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function test_an_unauthenticated_admin_request_is_marked_nosniff(): void
    {
        $response = $this->request('GET', '/api/admin/members');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }
}
