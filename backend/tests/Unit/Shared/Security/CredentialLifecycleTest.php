<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Security\CredentialLifecycle;
use PHPUnit\Framework\TestCase;

class CredentialLifecycleTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-08-13 12:00:00');
    }

    private function expiresIn(int $days): string
    {
        return $this->now->modify(sprintf('%+d days', $days))->format('Y-m-d H:i:s');
    }

    public function testStateTiersMatchAdr0035(): void
    {
        // > 90 days: OK; <= 90 INFO; <= 30 WARNING; <= 7 CRITICAL; < 0 EXPIRED
        $this->assertSame(CredentialLifecycle::STATE_OK, CredentialLifecycle::state($this->expiresIn(200), $this->now));
        $this->assertSame(CredentialLifecycle::STATE_OK, CredentialLifecycle::state($this->expiresIn(91), $this->now));
        $this->assertSame(CredentialLifecycle::STATE_INFO, CredentialLifecycle::state($this->expiresIn(90), $this->now));
        $this->assertSame(CredentialLifecycle::STATE_INFO, CredentialLifecycle::state($this->expiresIn(31), $this->now));
        $this->assertSame(CredentialLifecycle::STATE_WARNING, CredentialLifecycle::state($this->expiresIn(30), $this->now));
        $this->assertSame(CredentialLifecycle::STATE_WARNING, CredentialLifecycle::state($this->expiresIn(8), $this->now));
        $this->assertSame(CredentialLifecycle::STATE_CRITICAL, CredentialLifecycle::state($this->expiresIn(7), $this->now));
        $this->assertSame(CredentialLifecycle::STATE_CRITICAL, CredentialLifecycle::state($this->expiresIn(0), $this->now));
        $this->assertSame(CredentialLifecycle::STATE_EXPIRED, CredentialLifecycle::state($this->expiresIn(-1), $this->now));
    }

    public function testExpiryBoundaryIsHard(): void
    {
        // Still valid at the exact expiry second (0 whole days left)…
        $this->assertFalse(CredentialLifecycle::isExpired($this->expiresIn(0), $this->now));
        // …expired one second past it.
        $justPast = $this->now->modify('-1 second')->format('Y-m-d H:i:s');
        $this->assertTrue(CredentialLifecycle::isExpired($justPast, $this->now));
    }

    public function testDaysUntilExpiry(): void
    {
        $this->assertSame(365, CredentialLifecycle::daysUntilExpiry($this->expiresIn(365), $this->now));
        $this->assertSame(-3, CredentialLifecycle::daysUntilExpiry($this->expiresIn(-3), $this->now));
        $this->assertNull(CredentialLifecycle::daysUntilExpiry(null, $this->now));
        $this->assertNull(CredentialLifecycle::daysUntilExpiry('', $this->now));
    }

    public function testNoExpiryReadsAsOkNotExpired(): void
    {
        $this->assertSame(CredentialLifecycle::STATE_OK, CredentialLifecycle::state(null, $this->now));
        $this->assertFalse(CredentialLifecycle::isExpired(null, $this->now));
    }

    public function testExpiryFromActivationIs365Days(): void
    {
        $this->assertSame('2027-08-13 12:00:00', CredentialLifecycle::expiryFromActivation($this->now));
    }
}
