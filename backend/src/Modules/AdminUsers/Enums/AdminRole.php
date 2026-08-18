<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Enums;

/**
 * The three admin roles of ADR-0044 (Pattern 002).
 *
 * A ladder with one root: `admin` is a strict superset holding exactly what
 * every admin account holds today; `kassenwart` (the elected treasurer) and
 * `getraenkewart` (the bar stock) are lesser siblings beneath it. Roles compose
 * additively — one person may hold both lesser roles without becoming `admin`.
 * `admin` is never held alongside a lesser role: an account's role set is
 * either `admin` alone or a non-empty subset of {`kassenwart`,
 * `getraenkewart`} — enforced in `AdminUsersService::applyRoles()`.
 *
 * German names for the two club offices, English for `admin`, on the precedent
 * CONTEXT.md set for Storno, Deckel and Vorabankündigung: use the word the
 * people who read it actually say. `admin` is whoever holds the server, which
 * is not a Vereinsamt.
 *
 * Hardcoded rather than a seeded table — ADR-0044 alternative 2 rejects
 * permissions-as-data, so the whole vocabulary is this file plus the ENUM in
 * migration 044, and the two must agree.
 */
enum AdminRole: string
{
    case ADMIN = 'admin';
    case KASSENWART = 'kassenwart';
    case GETRAENKEWART = 'getraenkewart';

    /**
     * Every role, in declaration order — which is the order they are presented
     * and stored in, so lists do not reshuffle between two reads.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * Turn stored or submitted strings into roles, dropping anything that is
     * not one.
     *
     * Fail closed (ADR-0044 rule 1): an unrecognised value is not "some role
     * we do not know yet", it is not a role, and it never becomes one by
     * default. Duplicates collapse, and the result follows declaration order
     * regardless of the input's.
     *
     * @param iterable<mixed> $values
     * @return list<self>
     */
    public static function fromValues(iterable $values): array
    {
        $wanted = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $wanted[$value] = true;
            }
        }

        return array_values(array_filter(
            self::cases(),
            static fn (self $role): bool => isset($wanted[$role->value]),
        ));
    }

    /**
     * @param iterable<self> $roles
     * @return list<string>
     */
    public static function toValues(iterable $roles): array
    {
        $values = [];
        foreach ($roles as $role) {
            $values[] = $role->value;
        }

        return $values;
    }
}
