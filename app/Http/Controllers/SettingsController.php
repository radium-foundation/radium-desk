<?php

namespace App\Http\Controllers;

use App\Models\SettingProduct;
use App\Models\SettingSource;
use App\Services\DeviceModelAliasSettingsService;
use App\Services\DeviceModelSettingsService;
use App\Services\SettingService;
use App\Services\ApplicationSettingsService;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly ApplicationSettingsService $applicationSettingsService,
        private readonly DeviceModelSettingsService $deviceModelSettingsService,
        private readonly DeviceModelAliasSettingsService $deviceModelAliasSettingsService,
    ) {
        $this->middleware(function ($request, $next) {
            $this->authorize('viewAny', SettingProduct::class);

            return $next($request);
        });
    }

    public function index(): View
    {
        return view('settings.index', [
            'general' => [
                'company_name' => $this->settingService->get('general.company_name', 'Radium'),
                'company_email' => $this->settingService->get('general.company_email', ''),
                'timezone' => $this->settingService->get('general.timezone', config('app.timezone')),
            ],
            'assignment' => [
                'timezone' => $this->settingService->get('assignment.timezone', config('app.timezone')),
                'day_shift_start' => $this->settingService->get('assignment.day_shift_start', '09:00'),
                'day_shift_end' => $this->settingService->get('assignment.day_shift_end', '18:30'),
                'day_shift_admin_user_id' => $this->settingService->getInt('assignment.day_shift_admin_user_id'),
                'night_shift_admin_user_id' => $this->settingService->getInt('assignment.night_shift_admin_user_id'),
                'fallback_admin_1_user_id' => $this->settingService->getInt('assignment.fallback_admin_1_user_id'),
                'fallback_admin_2_user_id' => $this->settingService->getInt('assignment.fallback_admin_2_user_id'),
            ],
            'notifications' => [
                'assignment_enabled' => $this->settingService->getBool('notifications.assignment_enabled', true),
                'transaction_enabled' => $this->settingService->getBool('notifications.transaction_enabled', true),
                'high_priority_enabled' => $this->settingService->getBool('notifications.high_priority_enabled', true),
            ],
            'sla' => [
                'normal_warning_hours' => $this->settingService->getInt('sla.normal_warning_hours', 24),
                'normal_overdue_hours' => $this->settingService->getInt('sla.normal_overdue_hours', 48),
                'priority_warning_hours' => $this->settingService->getInt('sla.priority_warning_hours', 4),
                'priority_overdue_hours' => $this->settingService->getInt('sla.priority_overdue_hours', 8),
            ],
            'search' => [
                'order_id_enabled' => $this->settingService->getBool('search.order_id_enabled', true),
                'serial_number_enabled' => $this->settingService->getBool('search.serial_number_enabled', true),
                'transaction_id_enabled' => $this->settingService->getBool('search.transaction_id_enabled', true),
                'email_enabled' => $this->settingService->getBool('search.email_enabled', true),
                'mobile_enabled' => $this->settingService->getBool('search.mobile_enabled', true),
            ],
            'products' => $this->settingService->allProducts(),
            'sources' => $this->settingService->allSources(),
            'deviceModels' => $this->deviceModelSettingsService->paginated(
                search: request('search'),
            ),
            'deviceModelAliases' => $this->deviceModelAliasSettingsService->paginated(
                search: request('alias_search'),
            ),
            'deviceModelOptions' => $this->deviceModelAliasSettingsService->deviceModelOptions(),
            'adminUsers' => $this->applicationSettingsService->assignableAdminUsers(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }
}
