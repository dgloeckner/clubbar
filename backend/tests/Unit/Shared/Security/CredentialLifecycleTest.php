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

    /**
     * The tier is what goes into a warning's `dedup_key` (#438), so what is
     * being pinned here is not "which number comes back" but "how often does
     * this number change" — a tier that moved with the days remaining would
     * queue a fresh mail on every scheduler tick for ninety days.
     */
    public function testWarningTierIsTheTierEnteredNotTheDaysLeft(): void
    {
        // Outside every tier.
        $this->assertNull(CredentialLifecycle::warningTier($this->expiresIn(200), $this->now));
        $this->assertNull(CredentialLifecycle::warningTier($this->expiresIn(91), $this->now));

        // The 90-day tier holds for sixty days without changing value.
        $this->assertSame(90, CredentialLifecycle::warningTier($this->expiresIn(90), $this->now));
        $this->assertSame(90, CredentialLifecycle::warningTier($this->expiresIn(45), $this->now));
        $this->assertSame(90, CredentialLifecycle::warningTier($this->expiresIn(31), $this->now));

        $this->assertSame(30, CredentialLifecycle::warningTier($this->expiresIn(30), $this->now));
        $this->assertSame(30, CredentialLifecycle::warningTier($this->expiresIn(8), $this->now));

        $this->assertSame(7, CredentialLifecycle::warningTier($this->expiresIn(7), $this->now));
        $this->assertSame(7, CredentialLifecycle::warningTier($this->expiresIn(0), $this->now));
    }

    /**
     * These are advance warnings, and past the deadline there is nothing left to
     * warn in advance of — expiry is enforced where it bites and shown as an
     * error on the dashboard, so a fourth mail would be the least urgent of the
     * three places already saying so.
     */
    public function testWarningTierStopsAtExpiry(): void
    {
        $this->assertNull(CredentialLifecycle::warningTier($this->expiresIn(-1), $this->now));
        $this->assertNull(CredentialLifecycle::warningTier($this->expiresIn(-400), $this->now));
    }

    /** A credential with no expiry set — a PENDING key — warns about nothing. */
    public function testWarningTierIsNullWithoutAnExpiry(): void
    {
        $this->assertNull(CredentialLifecycle::warningTier(null, $this->now));
        $this->assertNull(CredentialLifecycle::warningTier('', $this->now));
    }

    /**
     * The tier list and the dashboard's state thresholds are the same three
     * numbers, and they have to stay the same three: a mail that fires on a tier
     * the banner does not show reads as a bug in whichever one is seen second.
     */
    public function testEveryTierHasAMatchingDashboardState(): void
    {
        $this->assertSame([90, 30, 7], CredentialLifecycle::WARNING_TIERS);

        foreach (CredentialLifecycle::WARNING_TIERS as $tier) {
            $expiresAt = $this->expiresIn($tier);

            $this->assertSame($tier, CredentialLifecycle::warningTier($expiresAt, $this->now));
            $this->assertNotSame(
                CredentialLifecycle::STATE_OK,
                CredentialLifecycle::state($expiresAt, $this->now),
                sprintf('The %d-day tier fires a mail while the dashboard still reads OK', $tier),
            );
        }
    }
}
