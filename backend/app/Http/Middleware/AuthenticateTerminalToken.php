<?php

namespace App\Http\Middleware;

use App\Http\Exceptions\UnauthorizedException;
use App\Models\Terminal;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * AuthenticateTerminalToken Middleware
 *
 * Validates Bearer token authentication for terminal API endpoints.
 * Attaches authenticated Terminal model to request for use in controllers.
 *
 * Flow:
 * 1. Extract Authorization header
 * 2. Validate Bearer format
 * 3. Query active terminals from database
 * 4. Constant-time verify token against each terminal's hash
 * 5. Attach terminal to request attributes
 * 6. Allow request to proceed or reject with 401
 *
 * Pattern 012: Terminal API Token Authentication
 * Pattern 007: Centralized Exception Handling (UnauthorizedException)
 *
 * @see \App\Http\Exceptions\UnauthorizedException
 * @see \App\Services\TokenService
 */
class AuthenticateTerminalToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     *
     * @throws UnauthorizedException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Extract and validate token from Authorization header
        $plainToken = $this->extractToken($request);

        // Find terminal with matching token hash
        $terminal = $this->findTerminalByToken($plainToken);

        // Attach terminal to request for use in controllers
        $request->attributes->set('terminal', $terminal);

        return $next($request);
    }

    /**
     * Extract Bearer token from Authorization header.
     *
     * @param  Request  $request
     * @return string Plaintext token
     *
     * @throws UnauthorizedException If header missing or invalid format
     */
    private function extractToken(Request $request): string
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            throw new UnauthorizedException(
                'Authorization header is missing',
                'authorization_header_missing',
                401
            );
        }

        // Validate Bearer format: "Bearer {token}"
        if (!preg_match('/^Bearer\s+(\S+)$/', $authHeader, $matches)) {
            throw new UnauthorizedException(
                'Authorization header must be in format: Bearer {token}',
                'invalid_authorization_format',
                401
            );
        }

        return $matches[1];
    }

    /**
     * Find terminal by token using constant-time comparison.
     *
     * Iterates through all active terminals and verifies token against each
     * using password_verify() for constant-time comparison.
     *
     * Time Complexity: O(n*m) where n = number of terminals, m = bcrypt verify time
     * Note: Linear search acceptable for typical terminal count (<100 devices per club)
     *
     * @param  string  $plainToken The plaintext token from the client
     * @return Terminal The authenticated terminal
     *
     * @throws UnauthorizedException If no terminal matches or terminal is inactive
     */
    private function findTerminalByToken(string $plainToken): Terminal
    {
        // Query all active terminals
        $activeTerminals = Terminal::active()->get();

        // Iterate and verify token against each (constant-time comparison)
        foreach ($activeTerminals as $terminal) {
            if ($terminal->api_token_hash && TokenService::verifyToken($plainToken, $terminal->api_token_hash)) {
                return $terminal;
            }
        }

        // No matching terminal found
        throw new UnauthorizedException(
            'The provided API token is invalid or expired',
            'invalid_terminal_token',
            401
        );
    }
}
