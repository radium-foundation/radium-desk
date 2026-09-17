<?php

namespace App\Http\Requests;

use App\Enums\RefundRevokeCustomerOutcome;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevokeRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(RolePermissionSeeder::PERMISSION_REFUNDS_REVOKE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_outcome' => ['required', 'string', Rule::enum(RefundRevokeCustomerOutcome::class)],
            'revoke_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_outcome.required' => 'Select what the customer wants after revoking this refund.',
            'revoke_reason.required' => 'A revoke reason is required.',
            'revoke_reason.min' => 'Provide a meaningful revoke reason.',
        ];
    }
}
