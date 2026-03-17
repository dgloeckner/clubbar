<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Config;

use App\Shared\Config\Env;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
    }

    protected function tearDown(): void
    {
        Env::reset();
        unset($_ENV['TEST_KEY_ENV'], $_ENV['TEST_FALSY_ZERO'], $_ENV['TEST_EMPTY_STRING'], $_ENV['INSTALL_KEY_TEST']);
    }

    public function test_get_returns_value_from_env_superglobal(): void
    {
        $_ENV['TEST_KEY_ENV'] = 'from-env';

        $this->assertSame('from-env', Env::get('TEST_KEY_ENV', 'default'));
    }

    public function test_get_returns_default_when_key_not_set(): void
    {
        $result = Env::get('DEFINITELY_NOT_SET_XYZ_123', 'my-default');

        $this->assertSame('my-default', $result);
    }

    public function test_get_throws_when_key_not_set_and_no_default(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing env var: DEFINITELY_NOT_SET_XYZ_123');

        Env::get('DEFINITELY_NOT_SET_XYZ_123');
    }

    public function test_get_returns_falsy_string_zero_not_default(): void
    {
        // The bug: '0' is falsy, must NOT return the default
        $_ENV['TEST_FALSY_ZERO'] = '0';

        $result = Env::get('TEST_FALSY_ZERO', 'default');

        $this->assertSame('0', $result, 'Falsy string "0" must be returned, not the default');
    }

    public function test_get_returns_empty_string_not_default_when_explicitly_set(): void
    {
        // Empty string set in $_ENV should return empty string, not default
        $_ENV['TEST_EMPTY_STRING'] = '';

        $result = Env::get('TEST_EMPTY_STRING', 'default');

        $this->assertSame('', $result, 'Empty string must be returned as-is, not replaced by default');
    }

    public function test_get_empty_install_key_returns_empty_not_default(): void
    {
        // install.php depends on this: empty key must return '' so the === '' check fires correctly
        $_ENV['INSTALL_KEY_TEST'] = '';

        $result = Env::get('INSTALL_KEY_TEST', '');

        $this->assertSame('', $result);
    }
}
