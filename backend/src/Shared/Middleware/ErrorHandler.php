<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Shared\Logging\Logger;
use App\Shared\Exceptions\AppException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Exceptions\InvalidCredentialsException;
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

            // Type-safe exception handling
            if ($e instanceof AppException) {
                $status = $e->getHttpStatusCode();
                $body = [
                    'error' => $e->getErrorCode(),
                    'message' => $e->getMessage()
                ];

                // Add validation errors if available
                if ($e instanceof ValidationException) {
                    $body['errors'] = $e->getErrors();
                }
            } else {
                // Fallback for non-AppException types
                $status = match (true) {
                    $e instanceof \Slim\Exception\HttpNotFoundException => 404,
                    $e instanceof \Slim\Exception\HttpMethodNotAllowedException => 405,
                    $e instanceof \InvalidArgumentException => 422,
                    default => 500,
                };

                $body = [
                    'error' => $this->errorCode($status, $e),
                    'message' => $e->getMessage()
                ];
            }

            if ($this->debug && !in_array($status, [401, 403, 404])) {
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
