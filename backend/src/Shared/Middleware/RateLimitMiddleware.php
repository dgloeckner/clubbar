<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PDO $pdo,
        private string $table = 'login_attempts',
        private int $maxAttempts = 5,
        private int $windowMinutes = 15,
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE ip_address = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL :window MINUTE)"
        );
        $stmt->execute(['ip' => $ip, 'window' => $this->windowMinutes]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $this->maxAttempts) {
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

        return $handler->handle($request);
    }
}
