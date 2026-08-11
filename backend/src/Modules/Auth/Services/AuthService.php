<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Logging\Logger;

class AuthService
{
    /**
     * Bcrypt hash of a random throwaway string, verified against when the
     * email is unknown so both branches cost the same and response timing
     * cannot reveal whether an account exists. Must keep the same algorithm
     * and cost as the real hashes created in AdminUsersService (bcrypt, 12).
     */
    private const DUMMY_HASH = '$2y$12$s//zzysohwTQDbAsrABRBuOh/JMgHgmbBZSlsyOP0ZWtYSSs7f5e6';

    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private Logger $logger,
    ) {}

    public function authenticate(string $email, string $password): ?array
    {
        $admin = $this->adminUsersRepository->findByEmail($email);

        $passwordValid = $this->verifyPassword($password, $admin['password_hash'] ?? self::DUMMY_HASH);

        if (!$admin) {
            $this->logger->info('Login failed: unknown email', ['email' => $email]);
            return null;
        }

        if (!$passwordValid) {
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

    protected function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
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
