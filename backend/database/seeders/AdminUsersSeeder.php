<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Shared\Constants\TestCredentials;
use Illuminate\Database\Seeder;

/**
 * AdminUsersSeeder - Create test admin users
 *
 * Creates a test admin account for local development and testing.
 * Uses fixed credentials from TestCredentials for reproducible test setup.
 * Pattern 013: Admin Session Authentication
 *
 * Credentials:
 * - Email: admin@example.com
 * - Password: password123
 *
 * Usage: Tests use these credentials to log in and make authenticated requests
 */
class AdminUsersSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Create a test admin user with known credentials
        AdminUser::create([
            'id' => '33e4567-e89b-12d3-a456-426614174000',
            'email' => TestCredentials::ADMIN_EMAIL,
            'password' => TestCredentials::ADMIN_PASSWORD,  // Will be hashed by setPasswordAttribute
            'display_name' => 'Admin User',
            'locale' => 'de',
            'is_active' => true,
        ]);
    }
}
