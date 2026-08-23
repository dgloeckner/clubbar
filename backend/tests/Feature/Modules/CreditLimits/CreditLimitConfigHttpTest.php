<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditLimits;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * The club's ceiling, through the real stack (ADR-0046 decision 5, #557).
 *
 * The grant is the interesting part. Both verbs are TREASURY — deliberately
 * unlike `sepa-config`, whose read is TREASURY and whose writes are
 * `admin`-only. That asymmetry is about blast radius: a wrong Gläubiger-ID
 * means a rejected collection run, a wrong ceiling is undone by typing the
 * right number. Deciding what members may run up on their Deckel is the
 * Kassenwart's own job.
 */
class CreditLimitConfigHttpTest extends HttpTestCase
{
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';
    private const PATH = '/api/admin/credit-limit-config';

    /** @var list<string> */
    private array $createdAdmins = [];

    /** @var array<string, mixed>|null */
    private ?array $originalConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfig = $this->db->query('SELECT * FROM credit_limit_config WHERE id = 1')->fetch() ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->originalConfig !== null) {
            $this->db->prepare(
                'UPDATE credit_limit_config SET default_limit_cents = ?, warn_threshold_percent = ? WHERE id = 1'
            )->execute([
                $this->originalConfig['default_limit_cents'],
                $this->originalConfig['warn_threshold_percent'],
            ]);
        }

        foreach ($this->createdAdmins as $id) {
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }
        $this->createdAdmins = [];

        parent::tearDown();
    }

    public function test_the_treasurer_reads_the_clubs_ceiling(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        $response = $this->request('GET', self::PATH);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('default_limit_cents', $body);
        $this->assertArrayHasKey('warn_threshold_percent', $body);
    }

    public function test_the_treasurer_sets_the_clubs_ceiling_and_it_sticks(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        $response = $this->patch(['default_limit_cents' => 15_000, 'warn_threshold_percent' => 70]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(15_000, $this->decode($response)['default_limit_cents']);

        $reread = $this->decode($this->request('GET', self::PATH));
        $this->assertSame(15_000, $reread['default_limit_cents']);
        $this->assertSame(70, $reread['warn_threshold_percent']);
    }

    public function test_the_admin_keeps_both_verbs(): void
    {
        $this->signInHolding(AdminRole::ADMIN);

        $this->assertSame(200, $this->request('GET', self::PATH)->getStatusCode());
        $this->assertSame(200, $this->patch(['default_limit_cents' => 12_000, 'warn_threshold_percent' => 80])->getStatusCode());
    }

    /**
     * The drinks list has no business with the Deckel. ADR-0044's line is
     * "what does the office need", and the Getränkewart needs no ceiling.
     */
    public function test_the_getraenkewart_reaches_neither_verb(): void
    {
        $this->signInHolding(AdminRole::GETRAENKEWART);

        $read = $this->request('GET', self::PATH);
        $this->assertSame(403, $read->getStatusCode());
        $this->assertSame('insufficient_role', $this->decode($read)['error']);

        $write = $this->patch(['default_limit_cents' => 1_000, 'warn_threshold_percent' => 50]);
        $this->assertSame(403, $write->getStatusCode());
        $this->assertSame('insufficient_role', $this->decode($write)['error']);
    }

    /**
     * Zero is the club saying "cap nobody" — a real setting, not a validation
     * accident (ADR-0046 decision 3).
     */
    public function test_a_ceiling_of_zero_is_accepted(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        $response = $this->patch(['default_limit_cents' => 0, 'warn_threshold_percent' => 80]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->decode($response)['default_limit_cents']);
    }

    /**
     * A negative ceiling would pass the `<= 0` test as "unlimited" and grant
     * every member infinite credit by accident. It is refused at the boundary
     * rather than reinterpreted.
     */
    public function test_a_negative_ceiling_is_refused(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        $this->assertSame(422, $this->patch(['default_limit_cents' => -1, 'warn_threshold_percent' => 80])->getStatusCode());
    }

    public function test_an_out_of_range_warning_band_is_refused(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        foreach ([0, 101, -5] as $percent) {
            $response = $this->patch(['default_limit_cents' => 10_000, 'warn_threshold_percent' => $percent]);
            $this->assertSame(422, $response->getStatusCode(), "warn_threshold_percent {$percent} must be refused");
        }
    }

    public function test_a_non_integer_ceiling_is_refused(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        $this->assertSame(422, $this->patch(['default_limit_cents' => '100.50', 'warn_threshold_percent' => 80])->getStatusCode());
    }

    public function test_both_numbers_are_required(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        $this->assertSame(422, $this->patch(['default_limit_cents' => 10_000])->getStatusCode());
        $this->assertSame(422, $this->patch(['warn_threshold_percent' => 80])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('PATCH', self::PATH, $body, [], [
            'X-CSRF-Token' => $_SESSION['csrf_token'] ?? '',
        ]);
    }

    private function signInHolding(AdminRole ...$roles): void
    {
        $id = Uuid::v4();
        $this->createdAdmins[] = $id;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$id, "credit-limit-{$id}@example.test", self::PASSWORD_HASH, 'Credit Limits', 'de']);

        $grant = $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)');
        foreach ($roles as $role) {
            $grant->execute([$id, $role->value]);
        }

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['admin_user_id'] = $id;
        SessionTimeout::begin($_SESSION);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
}
