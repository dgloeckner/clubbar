<?php

namespace App\Http\Modules\Members\Requests;

use App\Http\Modules\Members\Enums\SupportedLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateMemberRequest
 *
 * Form request for updating members via admin API.
 * All fields are optional for PATCH requests.
 *
 * Implements Pattern 001: Form Requests
 * Implements Pattern 002: Enum for type-safe language values
 */
final class UpdateMemberRequest extends FormRequest
{
    /**
     * Define validation rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'min:1', 'max:255'],
            'last_name' => ['nullable', 'string', 'min:1', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'card_uid' => [
                'nullable',
                'string',
                Rule::unique('members', 'card_uid')->ignore($this->route('memberId')),
                'max:255',
            ],
            'preferred_language' => [
                'nullable',
                'string',
                Rule::enum(SupportedLanguage::class),
            ],
            'is_active' => ['nullable', 'boolean'],
            // SEPA fields: allow clearing by removing min requirement (empty string = clear field)
            'iban' => ['nullable', 'string', 'max:34'],
            'mandate_reference' => ['nullable', 'string', 'max:35'],
            'mandate_signed_at' => ['nullable', 'date_format:Y-m-d'],
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
            'email.email' => 'Email must be valid',
            'card_uid.unique' => 'This card UID is already assigned to another member',
            'preferred_language.enum' => 'Invalid language code',
        ];
    }

    /**
     * Get typed first name (nullable)
     *
     * @return string|null
     */
    public function firstName(): ?string
    {
        return $this->validated('first_name');
    }

    /**
     * Get typed last name (nullable)
     *
     * @return string|null
     */
    public function lastName(): ?string
    {
        return $this->validated('last_name');
    }

    /**
     * Get typed email (nullable)
     *
     * @return string|null
     */
    public function email(): ?string
    {
        return $this->validated('email');
    }

    /**
     * Get typed phone (nullable)
     *
     * @return string|null
     */
    public function phone(): ?string
    {
        return $this->validated('phone');
    }

    /**
     * Get typed card UID (nullable)
     *
     * @return string|null
     */
    public function cardUid(): ?string
    {
        return $this->validated('card_uid');
    }

    /**
     * Get typed language (nullable)
     *
     * @return SupportedLanguage|null
     */
    public function preferredLanguage(): ?SupportedLanguage
    {
        $language = $this->validated('preferred_language');
        return $language ? SupportedLanguage::from($language) : null;
    }

    /**
     * Get typed is_active (nullable)
     *
     * @return bool|null
     */
    public function isActive(): ?bool
    {
        return $this->validated('is_active');
    }

    /**
     * Get typed IBAN (nullable)
     *
     * @return string|null
     */
    public function iban(): ?string
    {
        return $this->validated('iban');
    }

    /**
     * Get typed mandate reference (nullable)
     *
     * @return string|null
     */
    public function mandateReference(): ?string
    {
        return $this->validated('mandate_reference');
    }

    /**
     * Get typed mandate signed date (nullable)
     *
     * @return string|null
     */
    public function mandateSignedAt(): ?string
    {
        return $this->validated('mandate_signed_at');
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
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(
                [
                    'error' => 'validation_failed',
                    'messages' => $validator->errors(),
                ],
                422
            )
        );
    }
}
