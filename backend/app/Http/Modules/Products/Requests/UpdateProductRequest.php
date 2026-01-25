<?php

namespace App\Http\Modules\Products\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * UpdateProductRequest
 *
 * Validates input for product updates.
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * All fields are optional (allows partial updates).
 * Same validation rules as create, but all nullable.
 */
class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'names' => 'nullable|array',
            'names.de' => 'required_with:names|string|max:100',
            'names.*' => 'nullable|string|max:100',
            'descriptions' => 'nullable|array',
            'descriptions.*' => 'nullable|string|max:1000',
            'price_cents' => 'nullable|integer|min:1',
            'category_id' => 'nullable|uuid',
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'names.array' => 'Names must be provided as language translations',
            'names.de.required_with' => 'Default language name (de) is required if names are provided',
            'names.de.string' => 'Name must be text',
            'names.de.max' => 'Name cannot exceed 100 characters',
            'names.*.string' => 'Each name translation must be text',
            'names.*.max' => 'Each name cannot exceed 100 characters',
            'descriptions.array' => 'Descriptions must be provided as language translations',
            'descriptions.*.string' => 'Each description must be text',
            'descriptions.*.max' => 'Each description cannot exceed 1000 characters',
            'price_cents.integer' => 'Price must be a whole number (in cents)',
            'price_cents.min' => 'Price must be greater than 0',
            'category_id.uuid' => 'Category ID must be a valid UUID',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * Returns JSON error response instead of redirect.
     *
     * @param Validator $validator
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
