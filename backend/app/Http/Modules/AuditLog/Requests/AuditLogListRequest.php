<?php

namespace App\Http\Modules\AuditLog\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AuditLogListRequest - Validated Audit Log Query Parameters
 *
 * Implements Pattern 001: Form Requests for Input Validation
 * Validates pagination and filtering parameters for audit log queries.
 */
class AuditLogListRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'limit' => 'integer|min:1|max:100',
            'offset' => 'integer|min:0',
            'filters' => 'array',
            'filters.date_from' => 'nullable|date_format:Y-m-d',
            'filters.date_to' => 'nullable|date_format:Y-m-d',
            'filters.action' => 'nullable|in:create,update,delete,anonymize,login,logout,login_failed,export,settlement_create,settlement_cancel,settlement_export',
            'filters.entity_type' => 'nullable|in:member,product,admin_user,terminal,settlement,sepa_config',
            'filters.admin_user_id' => 'nullable|uuid',
            'filters.search' => 'nullable|string|max:255',
        ];
    }

    /**
     * Get the limit parameter (default: 50)
     *
     * @return int
     */
    public function limit(): int
    {
        return (int) $this->input('limit', 50);
    }

    /**
     * Get the offset parameter (default: 0)
     *
     * @return int
     */
    public function offset(): int
    {
        return (int) $this->input('offset', 0);
    }

    /**
     * Get filters array
     *
     * @return array
     */
    public function filters(): array
    {
        return $this->input('filters', []);
    }
}
