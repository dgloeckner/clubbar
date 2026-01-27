<?php

namespace App\Http\Modules\Products\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * CreateProductRequest
 *
 * Validates input for product creation.
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Required Fields:
 * - names: Object with default language translation required, others optional
 * - price_cents: Integer > 0
 * - category_id: UUID of existing active category
 *
 * Optional Fields:
 * - descriptions: Object with language translations
 */
class CreateProductRequest extends FormRequest
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
            'names' => 'required|array',
            'names.de' => 'required|string|max:100',  // German is default language
            'names.*' => 'nullable|string|max:100',
            'descriptions' => 'nullable|array',
            'descriptions.*' => 'nullable|string|max:1000',
            'price_cents' => 'required|integer|min:1',
            'category_id' => 'required|uuid|exists:categories,id',
            'icon_name' => 'nullable|string|max:50',
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
            'names.required' => 'Product names are required',
            'names.array' => 'Names must be provided as language translations',
            'names.de.required' => 'Default language name (de) is required',
            'names.de.string' => 'Name must be text',
            'names.de.max' => 'Name cannot exceed 100 characters',
            'names.*.string' => 'Each name translation must be text',
            'names.*.max' => 'Each name cannot exceed 100 characters',
            'descriptions.array' => 'Descriptions must be provided as language translations',
            'descriptions.*.string' => 'Each description must be text',
            'descriptions.*.max' => 'Each description cannot exceed 1000 characters',
            'price_cents.required' => 'Price is required',
            'price_cents.integer' => 'Price must be a whole number (in cents)',
            'price_cents.min' => 'Price must be greater than 0',
            'category_id.required' => 'Category is required',
            'category_id.uuid' => 'Category ID must be a valid UUID',
            'category_id.exists' => 'Selected category does not exist',
            'icon_name.string' => 'Icon name must be text',
            'icon_name.max' => 'Icon name cannot exceed 50 characters',
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
