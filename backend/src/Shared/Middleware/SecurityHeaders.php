<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Response headers that hold for every route.
 *
 * `X-Content-Type-Options: nosniff` tells the browser to believe the
 * `Content-Type` we send instead of re-typing a response from its bytes. It
 * matters most on the one endpoint that streams a file an admin uploaded — the
 * mandate document — where sniffing an HTML-shaped body would run it as a page
 * in the admin panel's own origin. It is set globally rather than on that route
 * alone because the next endpoint to return stored bytes should not have to
 * remember (#107).
 *
 * `backend/public/.htaccess` sets the same header, so an Apache deployment with
 * mod_headers ends up sending it twice. That is why this lives here anyway: the
 * .htaccess covers nothing on the shared-hosting front controller, under nginx,
 * or behind the built-in PHP server, and the header has to hold wherever the
 * app runs. Two identical values are not a problem — the Fetch spec's "determine
 * nosniff" step splits on commas and reads the first one.
 *
 * Deliberately narrow: a CSP or `X-Frame-Options` belongs to whatever serves
 * the admin panel's HTML, not to a JSON API, and guessing one here would give
 * false assurance without protecting a document this app ever renders.
 */
class SecurityHeaders implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
