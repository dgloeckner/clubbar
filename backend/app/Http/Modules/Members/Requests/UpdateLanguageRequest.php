<?php

namespace App\Http\Modules\Members\Requests;

use App\Http\Modules\Members\Enums\SupportedLanguage;
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
     * Get custom validation messages
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'preferred_language.enum' => 'Invalid language code: ' . ($this->input('preferred_language') ?? ''),
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

    /**
     * Handle a failed validation attempt.
     * Return JSON error response instead of redirecting.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $message = $errors['preferred_language'][0] ?? 'Invalid request';

        // Include the invalid value in the message if it's an enum error
        $inputValue = $this->input('preferred_language');
        if ($inputValue && strpos($message, 'selected') !== false) {
            $message = "Invalid language code: {$inputValue}";
        }

        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(
                [
                    'error' => 'invalid_request',
                    'message' => $message,
                ],
                400
            )
        );
    }
}
