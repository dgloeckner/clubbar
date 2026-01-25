<?php

namespace Database\Seeders;

use App\Models\Terminal;
use App\Services\TokenService;
use App\Shared\Constants\TestCredentials;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * TerminalSeeder - Create test terminal for development and E2E tests
 *
 * Creates a test terminal with a fixed, known test token.
 * The token is deterministic (not random) to enable reproducible testing
 * without environment variable dependencies.
 *
 * Pattern 012: Terminal API Token Authentication
 *
 * Credentials:
 * - Device ID: test-device-001
 * - Token: test-terminal-token-do-not-use-in-production-0a1b2c3d4e5f6g7h
 * - Status: Active
 *
 * Usage:
 *   php artisan db:seed --class=TerminalSeeder
 *   Or: php artisan migrate:fresh --seed
 *
 * This seeder is idempotent - it checks if a test terminal exists before creating one.
 * Safe to run multiple times (won't create duplicates).
 */
class TerminalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if test terminal already exists
        $existing = Terminal::where('device_id', TestCredentials::TERMINAL_DEVICE_ID)->first();
        if ($existing) {
            $this->command->info("Test Terminal already exists (Device ID: {$existing->device_id})");
            return;
        }

        // Use fixed test token (deterministic, not random)
        $plainToken = TestCredentials::TERMINAL_TOKEN;
        $hashedToken = TokenService::hashToken($plainToken);

        // Create test terminal with fixed credentials
        $terminal = Terminal::create([
            'id' => Str::uuid(),
            'name' => TestCredentials::TERMINAL_NAME,
            'device_id' => TestCredentials::TERMINAL_DEVICE_ID,
            'api_token_hash' => $hashedToken,
            'is_active' => true,
        ]);

        // Output confirmation
        $this->command->info('Test Terminal Created');
        $this->command->line('');
        $this->command->info("Name: {$terminal->name}");
        $this->command->info("Device ID: {$terminal->device_id}");
        $this->command->info("Terminal ID: {$terminal->id}");
        $this->command->line('');
        $this->command->info('Token is hardcoded in TestCredentials constant');
        $this->command->info('Use in tests via Authorization header:');
        $this->command->line('  Authorization: Bearer ' . $plainToken);
        $this->command->line('');
        $this->command->info('No environment variables needed - credentials are deterministic');
    }
}
