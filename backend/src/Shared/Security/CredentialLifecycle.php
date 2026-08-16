<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * The shared expiry policy for long-lived credentials — IBAN encryption keys
 * and terminal tokens alike (ADR-0036): 365-day operational lifetime, advance
 * warnings at 90/30/7 days, hard enforcement at expiry.
 *
 * Computed at request time on purpose: shared hosting guarantees no cron
 * (ADR-0031), and enforcement must not depend on a scheduler anyway — every
 * protected operation checks validity when it runs.
 */
final class CredentialLifecycle
{
    public const LIFETIME_DAYS = 365;

    public const STATE_OK = 'ok';
    public const STATE_INFO = 'info';
    public const STATE_WARNING = 'warning';
    public const STATE_CRITICAL = 'critical';
    public const STATE_EXPIRED = 'expired';

    /**
     * The advance-warning tiers, widest first.
     *
     * One list rather than three loose constants, because #438 gave the tiers a
     * second reader: the dashboard asks *which state is this in*, and the
     * scheduled notifier asks *which tier has this credential just entered* —
     * and a mail that fires on a tier the banner does not show, or the other way
     * round, is exactly the inconsistency an admin reads as a bug in whichever
     * one they saw second.
     */
    public const WARNING_TIERS = [90, 30, 7];

    private const INFO_DAYS = self::WARNING_TIERS[0];
    private const WARNING_DAYS = self::WARNING_TIERS[1];
    private const CRITICAL_DAYS = self::WARNING_TIERS[2];

    /**
     * Whole days until expiry, negative once expired. Null when the credential
     * has no expiry set (e.g. a PENDING key that was never activated).
     */
    public static function daysUntilExpiry(?string $expiresAt, ?\DateTimeImmutable $now = null): ?int
    {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        $expiry = new \DateTimeImmutable($expiresAt);

        $seconds = $expiry->getTimestamp() - $now->getTimestamp();

        return (int) floor($seconds / 86400);
    }

    public static function isExpired(?string $expiresAt, ?\DateTimeImmutable $now = null): bool
    {
        $days = self::daysUntilExpiry($expiresAt, $now);
        return $days !== null && $days < 0;
    }

    public static function state(?string $expiresAt, ?\DateTimeImmutable $now = null): string
    {
        $days = self::daysUntilExpiry($expiresAt, $now);

        if ($days === null) {
            return self::STATE_OK;
        }

        return match (true) {
            $days < 0 => self::STATE_EXPIRED,
            $days <= self::CRITICAL_DAYS => self::STATE_CRITICAL,
            $days <= self::WARNING_DAYS => self::STATE_WARNING,
            $days <= self::INFO_DAYS => self::STATE_INFO,
            default => self::STATE_OK,
        };
    }

    /**
     * The tightest warning tier this credential has entered, or null when it is
     * outside all of them (#438).
     *
     * "Entered", not "reached": a key with 45 days left is inside the 90-day
     * tier and not yet inside the 30-day one, so it warns as 90 — and will warn
     * again as 30, and again as 7. That is what makes three tiers three
     * messages instead of one message repeated: the tier is what goes into the
     * `dedup_key`, and a value that changed on every scan would queue a fresh
     * warning on every tick.
     *
     * **An expired credential returns null.** These are advance warnings, and
     * there is nothing left to warn in advance of: expiry is enforced where it
     * bites — no sealing, no SEPA export, no terminal authentication — and it is
     * an error on the dashboard rather than a tier. A club whose scheduler only
     * starts running after the deadline gets no mail about it, which is correct:
     * by then the mail would be the third place saying so and the least urgent.
     */
    public static function warningTier(?string $expiresAt, ?\DateTimeImmutable $now = null): ?int
    {
        $days = self::daysUntilExpiry($expiresAt, $now);

        if ($days === null || $days < 0) {
            return null;
        }

        $tier = null;
        foreach (self::WARNING_TIERS as $candidate) {
            if ($days <= $candidate) {
                $tier = $candidate;
            }
        }

        return $tier;
    }

    /** expires_at for a credential activated now. */
    public static function expiryFromActivation(\DateTimeImmutable $activatedAt): string
    {
        return $activatedAt
            ->add(new \DateInterval('P' . self::LIFETIME_DAYS . 'D'))
            ->format('Y-m-d H:i:s');
    }
}
