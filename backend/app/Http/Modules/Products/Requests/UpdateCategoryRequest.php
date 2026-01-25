<?php

namespace App\Http\Modules\Products\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateCategoryRequest
 *
 * Validates input for category updates.
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Optional Fields:
 * - names: Array with language translations
 * - display_order: Integer for tab ordering
 */
class UpdateCategoryRequest extends FormRequest
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
            'names' => 'nullable|array|min:1',
            'names.*' => 'required_with:names|string|max:100',
            'display_order' => 'nullable|integer|min:1',
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
            'names.min' => 'At least one language name is required if names are provided',
            'names.*.required_with' => 'Name in each language is required',
            'names.*.string' => 'Name must be a text string',
            'names.*.max' => 'Name cannot exceed 100 characters',
            'display_order.integer' => 'Display order must be a number',
            'display_order.min' => 'Display order must be greater than 0',
        ];
    }
}
