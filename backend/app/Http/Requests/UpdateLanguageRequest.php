<?php

namespace App\Http\Requests;

use App\Enums\SupportedLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateLanguageRequest
 *
 * Form request for updating member language preference.
 * Validates language code against supported languages enum.
 *
 * Implements Pattern 001: Form Requests
 * Implements Pattern 002: Enum for type-safe language values
 */
final class UpdateLanguageRequest extends FormRequest
{
    /**
     * Define validation rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'preferred_language' => [
                'required',
                'string',
                Rule::enum(SupportedLanguage::class),
            ],
        ];
    }

    /**
     * Get typed language value from validated input
     *
     * @return SupportedLanguage
     */
    public function preferredLanguage(): SupportedLanguage
    {
        return SupportedLanguage::from($this->validated('preferred_language'));
    }
}
