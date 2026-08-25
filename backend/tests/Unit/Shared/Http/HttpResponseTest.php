<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

/**
 * What an answered request says back.
 *
 * {@see \App\Shared\Http\OutboundHttpClient} deliberately returns a bool: it
 * carries reports *about* the application — a heartbeat ping — and a monitor
 * that can fail the job it watches is a net loss. An upload is the opposite:
 * it is part of the work, and the caller cannot resume, back off or give up
 * without seeing what the server actually said.
 *
 * The header lookup is case-insensitive because the two headers this slice
 * depends on are the two nobody agrees on the casing of — `Retry-After` on a
 * 429 and `Location` on an upload-session create. HTTP says field names are
 * case-insensitive; a transport that assumed otherwise would back off for the
 * default interval instead of the one the server asked for, which is the
 * failure mode that gets an application throttled harder.
 *
 * Part of #691, epic #686.
 */
class HttpResponseTest extends TestCase
{
    public function test_a_2xx_is_a_success_and_nothing_else_is(): void
    {
        $this->assertTrue((new HttpResponse(200, ''))->isSuccess());
        $this->assertTrue((new HttpResponse(201, ''))->isSuccess());
        $this->assertTrue((new HttpResponse(204, ''))->isSuccess());

        $this->assertFalse((new HttpResponse(301, ''))->isSuccess());
        $this->assertFalse((new HttpResponse(404, ''))->isSuccess());
        $this->assertFalse((new HttpResponse(500, ''))->isSuccess());
        $this->assertFalse(
            (new HttpResponse(0, ''))->isSuccess(),
            'Status 0 is "the request never completed" — a DNS failure or a refused connection.'
        );
    }

    public function test_a_header_is_found_whatever_case_the_server_used(): void
    {
        $response = new HttpResponse(429, '', ['Retry-After' => '120']);

        $this->assertSame('120', $response->header('retry-after'));
        $this->assertSame('120', $response->header('RETRY-AFTER'));
        $this->assertSame('120', $response->header('Retry-After'));
    }

    public function test_a_header_the_server_did_not_send_is_null_rather_than_empty(): void
    {
        // Null and '' are different answers: one is "no such header", the other
        // is "the header is there and empty". A transport treating them alike
        // would read a missing Location as a valid upload URL.
        $this->assertNull((new HttpResponse(200, ''))->header('location'));
        $this->assertSame('', (new HttpResponse(200, '', ['Location' => '']))->header('location'));
    }

    public function test_a_json_body_is_decoded_and_a_broken_one_is_an_empty_array(): void
    {
        $this->assertSame(
            ['access_token' => 'abc', 'expires_in' => 3599],
            (new HttpResponse(200, '{"access_token":"abc","expires_in":3599}'))->json()
        );

        // An error page where JSON was expected is a real outcome — a proxy
        // returning HTML on a 502. The caller decides what to do about the
        // status; it must not have to guard a parse as well.
        $this->assertSame([], (new HttpResponse(502, '<html>gateway</html>'))->json());
        $this->assertSame([], (new HttpResponse(200, ''))->json());
        $this->assertSame(
            [],
            (new HttpResponse(200, '"a bare string"'))->json(),
            'A valid JSON scalar is still not an object; the caller wants keys.'
        );
    }

    public function test_the_status_and_body_are_carried_verbatim(): void
    {
        $response = new HttpResponse(207, 'partial');

        $this->assertSame(207, $response->status);
        $this->assertSame('partial', $response->body);
    }
}
