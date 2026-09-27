<?php

namespace App\Http\Requests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;

class RerouteRefundExecutionMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(RolePermissionSeeder::PERMISSION_REFUNDS_REROUTE_EXECUTION_METHOD) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reroute_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reroute_reason.required' => 'A re-route reason is required.',
            'reroute_reason.min' => 'Provide a meaningful re-route reason.',
        ];
    }
}
