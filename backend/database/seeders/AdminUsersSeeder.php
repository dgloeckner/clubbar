<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;

/**
 * AdminUsersSeeder - Create test admin users
 *
 * Creates a test admin account for local development and testing.
 * Pattern 013: Admin Session Authentication
 */
class AdminUsersSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Create a test admin user
        AdminUser::create([
            'id' => '33e4567-e89b-12d3-a456-426614174000',
            'email' => 'admin@example.com',
            'password' => 'password123',  // Will be hashed by setPasswordAttribute
            'display_name' => 'Admin User',
            'locale' => 'de',
            'is_active' => true,
        ]);
    }
}
