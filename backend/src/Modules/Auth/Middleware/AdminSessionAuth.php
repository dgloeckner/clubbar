<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use Slim\Psr7\Response;

class AdminSessionAuth implements MiddlewareInterface
{
    public function __construct(private AdminUsersRepository $adminUsersRepository) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('_session');
            session_start();
        }

        $adminId = $_SESSION['admin_user_id'] ?? null;
        if (!$adminId) {
            return $this->unauthorized();
        }

        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin || !(bool) $admin['is_active']) {
            return $this->unauthorized();
        }

        // Attach admin data to request attributes
        $request = $request->withAttribute('admin_user_id', $adminId);
        $request = $request->withAttribute('admin_user', $admin);

        return $handler->handle($request);
    }

    private function unauthorized(): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode(['error' => 'admin_not_authenticated']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
