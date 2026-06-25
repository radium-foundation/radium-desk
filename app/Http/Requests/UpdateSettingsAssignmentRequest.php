<?php

namespace App\Http\Requests;

use App\Models\SettingProduct;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', SettingProduct::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $adminUserRule = Rule::exists('users', 'id')->where(function ($query) {
            $query->where('is_active', true)->whereNull('deleted_at');
        });

        return [
            'timezone' => ['required', 'string', 'timezone:all'],
            'day_shift_start' => ['required', 'date_format:H:i'],
            'day_shift_end' => ['required', 'date_format:H:i'],
            'day_shift_admin_user_id' => ['required', 'integer', $adminUserRule],
            'night_shift_admin_user_id' => ['required', 'integer', $adminUserRule],
            'fallback_admin_1_user_id' => ['nullable', 'integer', $adminUserRule],
            'fallback_admin_2_user_id' => ['nullable', 'integer', $adminUserRule],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'fallback_admin_1_user_id' => $this->input('fallback_admin_1_user_id') ?: null,
            'fallback_admin_2_user_id' => $this->input('fallback_admin_2_user_id') ?: null,
        ]);
    }
}
