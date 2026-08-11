<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Logging\Logger;

class AuthService
{
    /**
     * Valid bcrypt hash used only to equalize the unknown-email login path.
     *
     * Keep the algorithm/cost in sync with password_hash(..., PASSWORD_BCRYPT)
     * for real admin passwords so account lookup failures perform comparable
     * hashing work to wrong-password failures.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$15tAHrVCUYblml1SGXgFZefUGRQ7r5nykf27M7xoLPNCepf2XzajO';

    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private Logger $logger,
    ) {}

    public function authenticate(string $email, string $password): ?array
    {
        $admin = $this->adminUsersRepository->findByEmail($email);
        $passwordHash = $admin['password_hash'] ?? self::DUMMY_PASSWORD_HASH;
        $passwordOk = $this->verifyPassword($password, $passwordHash);

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
