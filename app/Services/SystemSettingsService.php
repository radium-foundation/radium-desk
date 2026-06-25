<?php

namespace App\Services;

use App\Models\SettingProduct;
use App\Models\SettingSource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SystemSettingsService
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    /**
     * @param  array{company_name: string, company_email: string, timezone: string}  $data
     */
    public function updateGeneral(array $data): void
    {
        $this->settingService->setMany([
            'general.company_name' => $data['company_name'],
            'general.company_email' => $data['company_email'],
            'general.timezone' => $data['timezone'],
        ]);
    }

    /**
     * @param  array{
     *     timezone: string,
     *     day_shift_start: string,
     *     day_shift_end: string,
     *     day_shift_admin_user_id: int,
     *     night_shift_admin_user_id: int,
     *     fallback_admin_1_user_id: int|null,
     *     fallback_admin_2_user_id: int|null
     * }  $data
     */
    public function updateAssignment(array $data): void
    {
        $this->ensureAdminUsers([
            $data['day_shift_admin_user_id'],
            $data['night_shift_admin_user_id'],
            $data['fallback_admin_1_user_id'],
            $data['fallback_admin_2_user_id'],
        ]);

        $this->settingService->setMany([
            'assignment.timezone' => $data['timezone'],
            'assignment.day_shift_start' => $data['day_shift_start'],
            'assignment.day_shift_end' => $data['day_shift_end'],
            'assignment.day_shift_admin_user_id' => (string) $data['day_shift_admin_user_id'],
            'assignment.night_shift_admin_user_id' => (string) $data['night_shift_admin_user_id'],
            'assignment.fallback_admin_1_user_id' => $data['fallback_admin_1_user_id'] !== null
                ? (string) $data['fallback_admin_1_user_id'] : '',
            'assignment.fallback_admin_2_user_id' => $data['fallback_admin_2_user_id'] !== null
                ? (string) $data['fallback_admin_2_user_id'] : '',
        ]);
    }

    /**
     * @param  array{assignment_enabled: bool, transaction_enabled: bool, high_priority_enabled: bool}  $data
     */
    public function updateNotifications(array $data): void
    {
        $this->settingService->setMany([
            'notifications.assignment_enabled' => $data['assignment_enabled'] ? '1' : '0',
            'notifications.transaction_enabled' => $data['transaction_enabled'] ? '1' : '0',
            'notifications.high_priority_enabled' => $data['high_priority_enabled'] ? '1' : '0',
        ]);
    }

    /**
     * @param  array{
     *     normal_warning_hours: int,
     *     normal_overdue_hours: int,
     *     priority_warning_hours: int,
     *     priority_overdue_hours: int
     * }  $data
     */
    public function updateSla(array $data): void
    {
        $this->settingService->setMany([
            'sla.normal_warning_hours' => (string) $data['normal_warning_hours'],
            'sla.normal_overdue_hours' => (string) $data['normal_overdue_hours'],
            'sla.priority_warning_hours' => (string) $data['priority_warning_hours'],
            'sla.priority_overdue_hours' => (string) $data['priority_overdue_hours'],
        ]);
    }

    /**
     * @param  array<string, bool>  $data
     */
    public function updateSearch(array $data): void
    {
        $this->settingService->setMany([
            'search.order_id_enabled' => $data['order_id_enabled'] ? '1' : '0',
            'search.serial_number_enabled' => $data['serial_number_enabled'] ? '1' : '0',
            'search.transaction_id_enabled' => $data['transaction_id_enabled'] ? '1' : '0',
            'search.email_enabled' => $data['email_enabled'] ? '1' : '0',
            'search.mobile_enabled' => $data['mobile_enabled'] ? '1' : '0',
        ]);
    }

    public function createProduct(string $name, int $sortOrder): SettingProduct
    {
        return DB::transaction(function () use ($name, $sortOrder): SettingProduct {
            $product = SettingProduct::query()->create([
                'name' => $name,
                'sort_order' => $sortOrder,
                'is_enabled' => true,
            ]);

            return $product;
        });
    }

    public function updateProduct(SettingProduct $product, string $name, int $sortOrder): SettingProduct
    {
        $product->update([
            'name' => $name,
            'sort_order' => $sortOrder,
        ]);

        return $product->fresh();
    }

    public function toggleProduct(SettingProduct $product, bool $enabled): SettingProduct
    {
        $product->update(['is_enabled' => $enabled]);

        return $product->fresh();
    }

    public function createSource(string $key, string $label, string $icon, int $sortOrder): SettingSource
    {
        return SettingSource::query()->create([
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'sort_order' => $sortOrder,
            'is_enabled' => true,
        ]);
    }

    public function updateSource(SettingSource $source, string $label, string $icon, int $sortOrder): SettingSource
    {
        $source->update([
            'label' => $label,
            'icon' => $icon,
            'sort_order' => $sortOrder,
        ]);

        return $source->fresh();
    }

    public function toggleSource(SettingSource $source, bool $enabled): SettingSource
    {
        $source->update(['is_enabled' => $enabled]);

        return $source->fresh();
    }

    /**
     * @return list<User>
     */
    public function assignableAdminUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->role([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->all();
    }

    /**
     * @param  list<int|null>  $userIds
     */
    private function ensureAdminUsers(array $userIds): void
    {
        foreach (array_filter($userIds) as $userId) {
            $user = User::query()->find($userId);

            if ($user === null || $user->trashed() || ! $user->is_active || ! $user->hasAnyRole([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ])) {
                throw ValidationException::withMessages([
                    'assignment' => 'All assignment admins must be active admin users.',
                ]);
            }
        }
    }
}
