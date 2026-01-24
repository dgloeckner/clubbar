<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * HealthRequest
 *
 * Form request for health check endpoint.
 * Validates incoming request (currently no input validation needed).
 *
 * Demonstrates Pattern 001: Form Requests for consistent validation layer
 * across all endpoints, even those without input validation requirements.
 */
final class HealthRequest extends FormRequest
{
    /**
     * Define validation rules for incoming request
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // Health check endpoint has no input requirements
            // This FormRequest serves as a consistent validation layer
            // even when no validation rules are needed
        ];
    }
}
