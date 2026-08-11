<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\DTOs;

use App\Modules\AdminUsers\DTOs\AdminUserDto;
use PHPUnit\Framework\TestCase;

/**
 * `display_name` is nullable in the schema but install.php never set it for
 * the wizard's first admin, so every fresh install carried a NULL value
 * there and `fromRow()` threw a TypeError building the response (#330).
 */
class AdminUserDtoTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 'admin-1',
            'email' => 'admin@example.com',
            'display_name' => 'Ada Lovelace',
            'locale' => 'de',
            'is_active' => 1,
            'totp_enabled' => 0,
            'last_login_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    public function test_it_uses_the_stored_display_name_when_present(): void
    {
        $dto = AdminUserDto::fromRow($this->row());

        $this->assertSame('Ada Lovelace', $dto->displayName);
    }

    public function test_it_falls_back_to_the_email_when_display_name_is_null(): void
    {
        $dto = AdminUserDto::fromRow($this->row(['display_name' => null]));

        $this->assertSame('admin@example.com', $dto->displayName);
    }

    public function test_it_falls_back_to_the_email_when_display_name_is_empty(): void
    {
        $dto = AdminUserDto::fromRow($this->row(['display_name' => '']));

        $this->assertSame('admin@example.com', $dto->displayName);
    }
}
