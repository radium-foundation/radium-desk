<?php

namespace App\Http\Requests;

use App\Enums\TeamBroadcastAudience;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BroadcastTeamTelegramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:120'],
            'audience' => ['required', 'string', Rule::enum(TeamBroadcastAudience::class)],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
