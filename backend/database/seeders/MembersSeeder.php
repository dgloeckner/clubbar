<?php

namespace Database\Seeders;

use App\Models\Member;
use Illuminate\Database\Seeder;

class MembersSeeder extends Seeder
{
    /**
     * Seed the members table with test data.
     */
    public function run(): void
    {
        // Create test members for Playwright E2E tests
        Member::create([
            'id' => '123e4567-e89b-12d3-a456-426614174000',
            'card_uid' => '04:d2:3e:5a:10:80:80',
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'email' => 'max@example.com',
            'phone' => '+41791234567',
            'preferred_language' => 'de',
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'DE91ZZZ00000000001',
            'mandate_signed_at' => now()->subYears(1),
            'is_active' => true,
        ]);

        Member::create([
            'id' => '223e4567-e89b-12d3-a456-426614174001',
            'card_uid' => '04:d2:3e:5a:10:80:90',
            'first_name' => 'Anna',
            'last_name' => 'Schmidt',
            'email' => 'anna@example.com',
            'phone' => '+41798765432',
            'preferred_language' => 'en',
            'iban' => 'CH9300762011623852957',
            'mandate_reference' => 'CH91ZZZ00000000002',
            'mandate_signed_at' => now()->subMonths(6),
            'is_active' => true,
            'created_at' => \DateTime::createFromFormat('Y-m-d\TH:i:s', '2024-07-01T12:30:00'),
            'updated_at' => \DateTime::createFromFormat('Y-m-d\TH:i:s', '2025-01-21T10:00:00'),
        ]);

        // Create additional members for list filtering tests
        Member::create([
            'id' => '323e4567-e89b-12d3-a456-426614174002',
            'card_uid' => '04:d2:3e:5a:10:80:91',
            'first_name' => 'Peter',
            'last_name' => 'Müller',
            'email' => 'peter@example.com',
            'phone' => '+41791111111',
            'preferred_language' => 'de',
            'is_active' => false,
        ]);

        Member::create([
            'id' => '423e4567-e89b-12d3-a456-426614174003',
            'card_uid' => '04:d2:3e:5a:10:80:92',
            'first_name' => 'Susan',
            'last_name' => 'Johnson',
            'email' => 'susan@example.com',
            'phone' => '+41792222222',
            'preferred_language' => 'en',
            'is_active' => true,
        ]);
    }
}
