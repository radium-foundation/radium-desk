<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\UserManagementService;
use App\Support\UserAccessPermissionCatalog;
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
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(app(UserManagementService::class)->assignableRoles($actor))],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
            'bonvoice_extension' => ['nullable', 'string', 'max:50', Rule::unique('users', 'bonvoice_extension')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(app(UserAccessPermissionCatalog::class)->assignablePermissionNames())],
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
            'bonvoice_extension' => 'Mobile',
            'roles' => 'roles',
            'roles.*' => 'role',
        ];
    }

    protected function prepareForValidation(): void
    {
        $extension = $this->input('bonvoice_extension');
        $roles = $this->input('roles');

        if (! is_array($roles)) {
            $roles = $roles !== null ? [$roles] : [];
        }

        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'bonvoice_extension' => is_string($extension) && trim($extension) !== ''
                ? trim($extension)
                : null,
            'roles' => array_values(array_filter($roles, fn (mixed $role): bool => is_string($role) && $role !== '')),
            'permissions' => $this->normalizedPermissions(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function normalizedPermissions(): array
    {
        $permissions = $this->input('permissions');

        if (! is_array($permissions)) {
            return [];
        }

        $assignable = app(UserAccessPermissionCatalog::class)->assignablePermissionNames();

        return collect($permissions)
            ->filter(fn (mixed $permission): bool => is_string($permission) && in_array($permission, $assignable, true))
            ->unique()
            ->values()
            ->all();
    }
}
