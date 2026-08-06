<?php

namespace App\Http\Requests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommercialServiceRestorationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(RolePermissionSeeder::PERMISSION_COMMERCIAL_SERVICE_RESTORE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'finance_verified' => ['accepted'],
            'wallet_reversed_externally' => ['accepted'],
            'wallet_reversal_reference' => ['required', 'string', 'max:255'],
            'finance_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'finance_verified.accepted' => 'Finance Verified must be confirmed.',
            'wallet_reversed_externally.accepted' => 'Wallet Reversed Externally must be confirmed.',
        ];
    }
}
