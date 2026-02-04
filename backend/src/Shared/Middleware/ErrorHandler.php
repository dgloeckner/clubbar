<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Shared\Logging\Logger;
use Slim\Psr7\Response;

class ErrorHandler implements MiddlewareInterface
{
    public function __construct(
        private Logger $logger,
        private bool $debug = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->debug ? $e->getTraceAsString() : null,
            ]);

            $status = match (true) {
                $e instanceof \InvalidArgumentException => 422,
                str_contains($e->getMessage(), 'not found') => 404,
                str_contains($e->getMessage(), 'already exists') => 409,
                default => 500,
            };

            $body = ['error' => $this->errorCode($status), 'message' => $e->getMessage()];
            if ($this->debug) {
                $body['trace'] = $e->getTraceAsString();
            }

            $response = new Response($status);
            $response->getBody()->write(json_encode($body));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            404 => 'not_found',
            409 => 'conflict',
            422 => 'validation_error',
            default => 'internal_error',
        };
    }
}
