<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Logging\Logger;

class AuthService
{
    // Precomputed once: valid bcrypt hash of a random string with cost 10
    private const DUMMY_HASH = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUV123456';

    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private Logger $logger,
    ) {}

    public function authenticate(string $email, string $password): ?array
    {
        $admin = $this->adminUsersRepository->findByEmail($email);
        $hash = $admin['password_hash'] ?? self::DUMMY_HASH;

        $passwordOk = password_verify($password, $hash);

        if (!$admin) {
            $this->logger->info('Login failed: unknown email', ['email' => $email]);
            return null;
        }

        if (!$passwordOk) {
            $this->logger->info('Login failed: invalid password', ['email' => $email]);
            return null;
        }

        if (!(bool) $admin['is_active']) {
            $this->logger->info('Login failed: inactive account', ['email' => $email]);
            return null;
        }

        $this->adminUsersRepository->updateById($admin['id'], [
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('Login successful', ['admin_id' => $admin['id']]);
        return $admin;
    }

    public function getActiveAdmin(string $adminId): ?array
    {
        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin || !(bool) $admin['is_active']) {
            return null;
        }
        return $admin;
    }
}

