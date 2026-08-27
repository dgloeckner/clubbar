<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Members\Controllers\SyncController as MembersSyncController;
use App\Modules\Terminals\Services\TerminalTokenAuthenticator;
use App\Modules\Terminals\Services\TerminalsService;
use App\ServiceFactory;
use App\Shared\Config\AppConfig;
use App\Shared\Config\Env;
use App\Shared\Logging\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Wiring checks for the container.
 *
 * TerminalsService gained a third constructor argument with #106 — the config
 * that carries the token TTL. A miswired factory would not fail until a
 * terminal was created at runtime, so the wiring is asserted here.
 */
class ServiceFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['DISABLE_LOGIN_RATE_LIMITING'], $_ENV['IBAN_FINGERPRINT_KEY'], $_ENV['APP_ENV']);
        Env::reset();
    }

    // ─── ADR-0036 crypto wiring ──────────────────────────────────────────────

    public function test_the_encryption_stack_resolves_and_is_singleton(): void
    {
        // A non-published key: the sealed box refuses the repo's dev key
        // outside development APP_ENVs, and this test runs as "production".
        $_ENV['IBAN_FINGERPRINT_KEY'] = str_repeat('ab', 32);
        $_ENV['APP_ENV'] = 'production';
        Env::reset();

        $factory = $this->factory();

        $this->assertSame($factory->getIbanSealedBox(), $factory->getIbanSealedBox());
        $this->assertSame($factory->getEncryptionKeysRepository(), $factory->getEncryptionKeysRepository());
        $this->assertSame($factory->getEncryptionKeyService(), $factory->getEncryptionKeyService());
        // Not the controllers: their chains construct TotpService (via
        // StepUpAuthService), which reads the instance name from the database
        // at construction — impossible on this test's mocked PDO. The HTTP
        // suite resolves them through the real container instead.
    }

    public function test_members_repository_carries_the_sealed_box(): void
    {
        $_ENV['IBAN_FINGERPRINT_KEY'] = str_repeat('ab', 32);
        $_ENV['APP_ENV'] = 'production';
        Env::reset();

        $factory = $this->factory();

        $this->assertSame($factory->getMembersRepository(), $factory->getMembersRepository());
        $this->assertSame($factory->getMembersService(), $factory->getMembersService());
    }

    private function factory(): ServiceFactory
    {
        return new ServiceFactory(
            $this->createMock(PDO::class),
            new AppConfig(),
            $this->createMock(Logger::class),
        );
    }

    public function test_getTerminalsService_builds_a_service_carrying_the_app_config(): void
    {
        $service = $this->factory()->getTerminalsService();

        $this->assertInstanceOf(TerminalsService::class, $service);
    }

    public function test_getTerminalsService_returns_the_same_instance_each_time(): void
    {
        $factory = $this->factory();

        $this->assertSame($factory->getTerminalsService(), $factory->getTerminalsService());
    }

    /**
     * #446 moved `updateLanguage` onto `Validator`, adding it as a second
     * constructor argument — a miswired factory would not fail until the
     * endpoint was hit, so the wiring is asserted here like every other
     * controller factory method.
     */
    public function test_getMembersSyncController_builds_a_controller_and_is_singleton(): void
    {
        // getMembersService() resolves the members repository, which needs the
        // IBAN sealed box — same env this file's other IBAN-dependent test sets.
        $_ENV['IBAN_FINGERPRINT_KEY'] = str_repeat('ab', 32);
        $_ENV['APP_ENV'] = 'production';
        Env::reset();

        $factory = $this->factory();

        $this->assertInstanceOf(MembersSyncController::class, $factory->getMembersSyncController());
        $this->assertSame($factory->getMembersSyncController(), $factory->getMembersSyncController());
    }

    /**
     * #395 moved the promotion and the expiry audit out of the middleware into
     * TerminalTokenAuthenticator, which the middleware now takes as a third
     * argument. A miswired factory would not fail until a terminal tried to
     * sync — i.e. in front of a bar — so the wiring is asserted here.
     */
    public function test_the_terminal_authentication_chain_resolves_and_is_singleton(): void
    {
        $factory = $this->factory();

        $this->assertInstanceOf(TerminalTokenAuthenticator::class, $factory->getTerminalTokenAuthenticator());
        $this->assertSame(
            $factory->getTerminalTokenAuthenticator(),
            $factory->getTerminalTokenAuthenticator(),
        );
        $this->assertSame($factory->getTerminalTokenAuth(), $factory->getTerminalTokenAuth());
    }

    // ─── Login rate limiter disable flag (#338) ──────────────────────────────
    //
    // A replayed TOTP code records a login_attempts row (rejectMfaCode), and
    // E2E tests share one seeded admin's TOTP secret across many parallel
    // logins — benign same-window collisions between them would otherwise
    // trip this IP-wide limiter and 429 every subsequent login, not just
    // TOTP ones. DISABLE_LOGIN_RATE_LIMITING is the escape hatch for that.

    private function isDisabled(object $rateLimitMiddleware): bool
    {
        $property = new \ReflectionProperty($rateLimitMiddleware, 'disabled');
        $property->setAccessible(true);
        return $property->getValue($rateLimitMiddleware);
    }

    public function test_getRateLimitMiddleware_is_active_by_default(): void
    {
        // unset($_ENV[...]) cannot clear DISABLE_LOGIN_RATE_LIMITING: docker-compose
        // sets it as a real process env var, which Env::get() falls through to once
        // the $_ENV entry is gone. Set $_ENV explicitly so it shadows that fallback
        // instead of hoping the process environment stays quiet.
        $_ENV['DISABLE_LOGIN_RATE_LIMITING'] = 'false';

        $this->assertFalse($this->isDisabled($this->factory()->getRateLimitMiddleware()));
    }

    public function test_getRateLimitMiddleware_is_disabled_via_env_var(): void
    {
        $_ENV['DISABLE_LOGIN_RATE_LIMITING'] = 'true';

        $this->assertTrue($this->isDisabled($this->factory()->getRateLimitMiddleware()));
    }
}
