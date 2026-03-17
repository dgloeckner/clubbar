<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class JsonBodyParser implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            if ($body !== '') {
                $parsed = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $response = new Response(400);
                    $response->getBody()->write(json_encode([
                        'error' => 'invalid_json',
                        'message' => 'Request body contains malformed JSON: ' . json_last_error_msg(),
                    ]));
                    return $response->withHeader('Content-Type', 'application/json');
                }
                $request = $request->withParsedBody($parsed);
            }
        }
        return $handler->handle($request);
    }
}
