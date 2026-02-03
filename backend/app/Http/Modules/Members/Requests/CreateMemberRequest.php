<?php

namespace App\Http\Modules\Members\Requests;

use App\Http\Modules\Members\Enums\SupportedLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CreateMemberRequest
 *
 * Form request for creating new members via admin API.
 * Validates member data: name, email, phone, card_uid, language.
 *
 * Implements Pattern 001: Form Requests
 * Implements Pattern 002: Enum for type-safe language values
 */
final class CreateMemberRequest extends FormRequest
{
    /**
     * Define validation rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'min:1', 'max:255'],
            'last_name' => ['required', 'string', 'min:1', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'card_uid' => ['nullable', 'string', 'unique:members,card_uid', 'max:255'],
            'preferred_language' => [
                'required',
                'string',
                Rule::enum(SupportedLanguage::class),
            ],
            'iban' => ['nullable', 'string', 'min:15', 'max:34'],
            'account_holder_name' => ['nullable', 'string', 'max:70'],
            'mandate_reference' => ['nullable', 'string', 'min:1', 'max:35'],
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
            'first_name.required' => 'First name is required',
            'last_name.required' => 'Last name is required',
            'email.required' => 'Email is required',
            'email.email' => 'Email must be valid',
            'card_uid.unique' => 'This card UID is already assigned to another member',
            'preferred_language.enum' => 'Invalid language code',
        ];
    }

    /**
     * Get typed first name
     *
     * @return string
     */
    public function firstName(): string
    {
        return $this->validated('first_name');
    }

    /**
     * Get typed last name
     *
     * @return string
     */
    public function lastName(): string
    {
        return $this->validated('last_name');
    }

    /**
     * Get typed email
     *
     * @return string
     */
    public function email(): string
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
     * Get typed language
     *
     * @return SupportedLanguage
     */
    public function preferredLanguage(): SupportedLanguage
    {
        return SupportedLanguage::from($this->validated('preferred_language'));
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
     * Get typed account holder name (nullable)
     *
     * @return string|null
     */
    public function accountHolderName(): ?string
    {
        return $this->validated('account_holder_name');
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
