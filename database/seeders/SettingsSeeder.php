<?php

namespace Database\Seeders;

use App\Models\SettingProduct;
use App\Models\SettingSource;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settingService = app(SettingService::class);

        $dayAdmin = User::query()->where('email', 'admin@radium.local')->first();
        $fallbackAdmin = User::query()->where('email', 'superadmin@radium.local')->first();

        $settingService->setMany([
            'general.company_name' => 'Radium',
            'general.company_email' => 'support@radiumbox.com',
            'general.timezone' => config('service_case_assignment.timezone', 'Asia/Kolkata'),
            'assignment.timezone' => config('service_case_assignment.timezone', 'Asia/Kolkata'),
            'assignment.day_shift_start' => config('service_case_assignment.day_shift.start', '09:00'),
            'assignment.day_shift_end' => config('service_case_assignment.day_shift.end', '18:30'),
            'assignment.day_shift_admin_user_id' => (string) ($dayAdmin?->id ?? ''),
            'assignment.night_shift_admin_user_id' => (string) ($dayAdmin?->id ?? ''),
            'assignment.fallback_admin_1_user_id' => (string) ($fallbackAdmin?->id ?? ''),
            'assignment.fallback_admin_2_user_id' => (string) ($dayAdmin?->id ?? ''),
            'notifications.assignment_enabled' => '1',
            'notifications.transaction_enabled' => '1',
            'notifications.high_priority_enabled' => '1',
            'sla.normal_warning_hours' => '24',
            'sla.normal_overdue_hours' => '48',
            'sla.priority_warning_hours' => '4',
            'sla.priority_overdue_hours' => '8',
            'search.order_id_enabled' => '1',
            'search.serial_number_enabled' => '1',
            'search.transaction_id_enabled' => '1',
            'search.email_enabled' => '1',
            'search.mobile_enabled' => '1',
        ]);

        foreach (config('products', []) as $index => $productName) {
            SettingProduct::query()->updateOrCreate(
                ['name' => $productName],
                [
                    'sort_order' => $index + 1,
                    'is_enabled' => true,
                ],
            );
        }

        $sources = [
            ['key' => 'call', 'label' => 'Call', 'icon' => 'bi-telephone-fill', 'sort_order' => 1],
            ['key' => 'whatsapp', 'label' => 'WhatsApp', 'icon' => 'bi-whatsapp', 'sort_order' => 2],
            ['key' => 'email', 'label' => 'Email', 'icon' => 'bi-envelope-fill', 'sort_order' => 3],
            ['key' => 'telegram', 'label' => 'Telegram', 'icon' => 'bi-telegram', 'sort_order' => 4],
            ['key' => 'internal', 'label' => 'Internal', 'icon' => 'bi-building', 'sort_order' => 5],
            ['key' => 'cashfree', 'label' => 'Cashfree', 'icon' => 'bi-credit-card', 'sort_order' => 6],
        ];

        foreach ($sources as $source) {
            SettingSource::query()->updateOrCreate(
                ['key' => $source['key']],
                [
                    'label' => $source['label'],
                    'icon' => $source['icon'],
                    'sort_order' => $source['sort_order'],
                    'is_enabled' => true,
                ],
            );
        }
    }
}
