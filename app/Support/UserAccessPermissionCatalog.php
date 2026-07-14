<?php

namespace App\Support;

use Database\Seeders\RolePermissionSeeder;

class UserAccessPermissionCatalog
{
    /**
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public function groups(): array
    {
        return [
            'operations' => [
                'label' => 'Operations',
                'permissions' => [
                    RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY => 'Correct Order Identity',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function assignablePermissionNames(): array
    {
        return collect($this->groups())
            ->flatMap(fn (array $group): array => array_keys($group['permissions']))
            ->values()
            ->all();
    }
}
