<?php

namespace App\Http\Exceptions;

use Exception;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UnauthorizedException - Terminal API Authentication Failure
 *
 * Thrown when a request to protected endpoints fails authentication.
 * Implements Responsable for custom JSON error responses.
 *
 * Pattern 007: Centralized Exception Handling (via Responsable)
 * Pattern 012: Terminal API Token Authentication (error responses)
 *
 * Error Codes:
 * - 'authorization_header_missing': No Authorization header provided
 * - 'invalid_authorization_format': Header not in "Bearer {token}" format
 * - 'invalid_terminal_token': Token doesn't match any active terminal
 * - 'terminal_inactive': Terminal exists but is_active = false
 */
class UnauthorizedException extends Exception implements Responsable
{
    private string $errorCode;
    private int $statusCode;

    /**
     * Create a new exception instance.
     *
     * @param string $message Human-readable error message
     * @param string $errorCode Machine-readable error code
     * @param int $statusCode HTTP status code
     */
    public function __construct(
        string $message = 'Unauthorized',
        string $errorCode = 'unauthorized',
        int $statusCode = 401
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
    }

    /**
     * Render the exception as an HTTP response.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function toResponse($request): JsonResponse
    {
        return response()->json(
            [
                'error' => $this->errorCode,
                'message' => $this->message,
            ],
            $this->statusCode
        );
    }
}
