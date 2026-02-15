<?php

declare(strict_types=1);

namespace App\Modules\Products\Validators;

/**
 * Icon Name Validator
 *
 * Validates product icon names against the canonical icon registry
 * defined in docs/icon-registry.md
 *
 * Ensures consistency across backend, admin frontend, and Flutter terminal.
 */
class IconNameValidator
{
    /**
     * Canonical icon names from docs/icon-registry.md
     *
     * Format: lowercase kebab-case (e.g., "beer-pils", "sauna-session")
     */
    private const CANONICAL_ICONS = [
        // Beverages - Beer
        'beer-pils',
        'beer-weizen',
        'beer-radler',
        'beer-alcohol-free',

        // Beverages - Cider & Spritzers
        'cider-apfelwein',
        'spritzer-apple',

        // Beverages - Hot Drinks
        'coffee',

        // Beverages - Water & Soft Drinks
        'water',
        'water-large',
        'soda',

        // Food
        'food-pizza',
        'food-sandwich',
        'snack-chips',
        'snack',

        // Services - Sauna
        'sauna-session',
        'sauna-cabin',
        'sauna-infusion',
        'sauna-towel',
        'sauna-token',

        // Special
        'correction',
        'unknown',
    ];

    /**
     * Validate icon name is canonical
     *
     * @param string|null $iconName Icon name to validate
     * @return bool True if valid canonical name or null, false otherwise
     */
    public static function isValid(?string $iconName): bool
    {
        if ($iconName === null) {
            return true; // null is allowed (no icon)
        }

        return in_array(strtolower($iconName), self::CANONICAL_ICONS, true);
    }

    /**
     * Validate icon name and throw exception if invalid
     *
     * @param string|null $iconName Icon name to validate
     * @throws \InvalidArgumentException If icon name is not canonical
     */
    public static function validate(?string $iconName): void
    {
        if (!self::isValid($iconName)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid icon name: "%s". Must be one of: %s. See docs/icon-registry.md',
                    $iconName,
                    implode(', ', self::CANONICAL_ICONS)
                )
            );
        }
    }

    /**
     * Get all canonical icon names
     *
     * @return array<string> List of valid canonical icon names
     */
    public static function getCanonicalNames(): array
    {
        return self::CANONICAL_ICONS;
    }

    /**
     * Check if icon name is legacy format (camelCase with "Icon" suffix)
     *
     * @param string $iconName Icon name to check
     * @return bool True if legacy format, false otherwise
     */
    public static function isLegacyFormat(string $iconName): bool
    {
        return str_ends_with($iconName, 'Icon') && preg_match('/[A-Z]/', $iconName);
    }
}
