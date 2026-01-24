<?php

namespace App\Enums;

/**
 * SupportedLanguage
 *
 * Type-safe enum for supported member language preferences.
 *
 * Implements Pattern 002: Enum for type-safe domain values
 */
enum SupportedLanguage: string
{
    case German = 'de';
    case English = 'en';
    case French = 'fr';
}
