<?php

namespace App\Http\Requests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;

class LockPayrollMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
