<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\UserManagementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $actor */
        $actor = $this->user();

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', Rule::in(app(UserManagementService::class)->assignableRoles($actor))],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
            'bonvoice_extension' => ['nullable', 'string', 'max:50', Rule::unique('users', 'bonvoice_extension')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'is_active' => 'status',
            'bonvoice_extension' => 'BonVoice extension / desk number',
        ];
    }

    protected function prepareForValidation(): void
    {
        $extension = $this->input('bonvoice_extension');

        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'bonvoice_extension' => is_string($extension) && trim($extension) !== ''
                ? trim($extension)
                : null,
        ]);
    }
}
