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
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(private PDO $pdo) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL :window MINUTE)'
        );
        $stmt->execute(['ip' => $ip, 'window' => self::WINDOW_MINUTES]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= self::MAX_ATTEMPTS) {
            $response = new SlimResponse(429);
            $response->getBody()->write(json_encode([
                'error' => 'too_many_attempts',
                'message' => 'Too many login attempts. Please try again later.',
                'retry_after_seconds' => self::WINDOW_MINUTES * 60,
            ]));
            return $response->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string)(self::WINDOW_MINUTES * 60));
        }

        return $handler->handle($request);
    }
}
