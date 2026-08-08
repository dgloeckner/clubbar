<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Pre-check that blocks an endpoint once failed attempts pile up.
 *
 * Ruling #145: the login surface counts on two dimensions — per source IP and
 * per account — because either alone leaves a hole. Per-IP alone gives a club's
 * shared WiFi one budget for several admins while a distributed attacker never
 * touches it; per-account alone lets one host cycle freely through admin1@,
 * admin2@, admin3@.
 *
 * The account dimension is opt-in: `$accountResolver` extracts the email under
 * attack from the request (the login body, or the MFA-pending session). Routes
 * with no account to speak of — terminal token auth — pass null and keep the
 * IP-only behaviour.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param (Closure(Request): ?string)|null $accountResolver
     */
    public function __construct(
        private LoginAttemptsRepository $attempts,
        private int $maxAttempts = 5,
        private int $windowMinutes = 15,
        private bool $disabled = false,
        private ?Closure $accountResolver = null,
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if ($this->disabled) {
            return $handler->handle($request);
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';

        if ($this->attempts->countRecentByIp($ip, $this->windowMinutes) >= $this->maxAttempts) {
            return $this->tooManyAttempts();
        }

        $email = $this->accountResolver !== null ? ($this->accountResolver)($request) : null;

        if (is_string($email) && $email !== ''
            && $this->attempts->countRecentByEmail($email, $this->windowMinutes) >= $this->maxAttempts
        ) {
            return $this->tooManyAttempts();
        }

        return $handler->handle($request);
    }

    private function tooManyAttempts(): Response
    {
        $retryAfter = $this->windowMinutes * 60;
        $response = new SlimResponse(429);
        $response->getBody()->write(json_encode([
            'error' => 'too_many_attempts',
            'message' => 'Too many failed authentication attempts. Please try again later.',
            'retry_after_seconds' => $retryAfter,
        ]));

        return $response->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string) $retryAfter);
    }
}
