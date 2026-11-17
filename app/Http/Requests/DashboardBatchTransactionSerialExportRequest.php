<?php

namespace App\Http\Requests;

use App\Enums\WorkspaceContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardBatchTransactionSerialExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'incident_ids' => ['required', 'array', 'min:1'],
            'incident_ids.*' => ['required', 'integer', 'exists:incidents,id'],
            'context' => ['nullable', 'string', Rule::in(WorkspaceContext::values())],
        ];
    }
}
