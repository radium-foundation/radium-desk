<?php

namespace App\Http\Requests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateOrderTransactionRequest extends FormRequest
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
            'transaction_id' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'transaction_id' => 'transaction ID',
            'incident_ids' => 'service cases',
        ];
    }
}
