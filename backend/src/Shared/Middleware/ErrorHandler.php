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
                $e instanceof \Slim\Exception\HttpNotFoundException => 404,
                $e instanceof \Slim\Exception\HttpMethodNotAllowedException => 405,
                $e instanceof \InvalidArgumentException => 422,
                str_contains($e->getMessage(), 'not found') => 404,
                str_contains($e->getMessage(), 'already exists') => 409,
                str_contains($e->getMessage(), 'Cannot deactivate') => 409,
                str_contains($e->getMessage(), 'Cannot ') => 400,
                default => 500,
            };

            $body = ['error' => $this->errorCode($status, $e), 'message' => $e->getMessage()];
            if ($this->debug) {
                $body['trace'] = $e->getTraceAsString();
            }

            $response = new Response($status);
            $response->getBody()->write(json_encode($body));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    private function errorCode(int $status, \Throwable $e): string
    {
        return match (true) {
            $status === 409 => 'business_rule_violation',
            $status === 400 => 'invalid_request',
            $status === 401 => 'unauthorized',
            $status === 404 => 'not_found',
            $status === 422 && $e instanceof \InvalidArgumentException => 'validation_failed',
            $status === 422 => 'validation_failed',
            default => 'internal_error',
        };
    }
}
