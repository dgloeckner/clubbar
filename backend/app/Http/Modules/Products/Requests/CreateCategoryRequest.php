<?php

namespace App\Http\Modules\Products\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CreateCategoryRequest
 *
 * Validates input for category creation.
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Required Fields:
 * - names: Array with at least one language translation
 */
class CreateCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization checked by middleware (AuthenticateAdminSession).
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
            'names' => 'required|array|min:1',
            'names.*' => 'required|string|max:100',
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
            'names.required' => 'At least one language name is required',
            'names.array' => 'Names must be provided as language translations',
            'names.min' => 'At least one language name is required',
            'names.*.required' => 'Name in each language is required',
            'names.*.string' => 'Name must be a text string',
            'names.*.max' => 'Name cannot exceed 100 characters',
        ];
    }
}
