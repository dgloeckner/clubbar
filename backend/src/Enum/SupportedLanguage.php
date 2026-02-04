<?php

declare(strict_types=1);

namespace App\Enum;

enum SupportedLanguage: string
{
    case German = 'de';
    case English = 'en';
    case French = 'fr';
}