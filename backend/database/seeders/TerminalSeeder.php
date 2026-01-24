<?php

namespace Database\Seeders;

use App\Models\Terminal;
use App\Services\TokenService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * TerminalSeeder - Create test terminal for development and E2E tests
 *
 * Creates a test terminal with a generated token.
 * The plaintext token is output to console for use in test setup.
 *
 * Pattern 012: Terminal API Token Authentication
 *
 * Usage:
 *   php artisan db:seed --class=TerminalSeeder
 */
class TerminalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Generate a new token and hash it
        $plainToken = TokenService::generateTerminalToken();
        $hashedToken = TokenService::hashToken($plainToken);

        // Create test terminal
        $terminal = Terminal::create([
            'id' => Str::uuid(),
            'name' => 'Test Terminal',
            'device_id' => 'test-device-001',
            'api_token_hash' => $hashedToken,
            'is_active' => true,
        ]);

        // Output token for test configuration
        $this->command->info('Test Terminal Created');
        $this->command->line('');
        $this->command->info("Terminal ID: {$terminal->id}");
        $this->command->info("Device ID: {$terminal->device_id}");
        $this->command->line('');
        $this->command->warn('API Token (save this - it will not be shown again):');
        $this->command->line('');
        $this->command->info($plainToken);
        $this->command->line('');
        $this->command->line('Use this token in tests via Authorization header:');
        $this->command->line('  Authorization: Bearer ' . $plainToken);
    }
}
