<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * The shared expiry policy for long-lived credentials — IBAN encryption keys
 * and terminal tokens alike (ADR-0035): 365-day operational lifetime, advance
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

    private const INFO_DAYS = 90;
    private const WARNING_DAYS = 30;
    private const CRITICAL_DAYS = 7;

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

    /** expires_at for a credential activated now. */
    public static function expiryFromActivation(\DateTimeImmutable $activatedAt): string
    {
        return $activatedAt
            ->add(new \DateInterval('P' . self::LIFETIME_DAYS . 'D'))
            ->format('Y-m-d H:i:s');
    }
}
