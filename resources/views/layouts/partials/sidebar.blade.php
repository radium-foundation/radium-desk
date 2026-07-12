@php
    use Database\Seeders\RolePermissionSeeder;
@endphp

<aside class="app-sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="brand d-flex align-items-center px-3">
        @include('layouts.partials.brand-mark')
    </div>

    <nav class="py-2">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a @class(['nav-link', 'active' => request()->routeIs('dashboard')]) href="{{ route('dashboard') }}" title="Dashboard">
                    <i class="bi bi-speedometer2 nav-icon me-2"></i>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a @class(['nav-link', 'active' => request()->routeIs('search.*')]) href="{{ route('search.index') }}" title="Search">
                    <i class="bi bi-search nav-icon me-2"></i>
                    <span class="nav-label">Search</span>
                </a>
            </li>
        </ul>

        <div class="nav-section"><span class="nav-label">Operations</span></div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a @class(['nav-link', 'active' => request()->routeIs('orders.*')]) href="{{ route('orders.index') }}" title="Orders">
                    <i class="bi bi-box-seam nav-icon me-2"></i>
                    <span class="nav-label">Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a @class(['nav-link', 'active' => request()->routeIs('incidents.*')]) href="{{ route('incidents.index') }}" title="{{ config('ui.service_case.plural') }}">
                    <i class="bi bi-exclamation-triangle nav-icon me-2"></i>
                    <span class="nav-label">{{ config('ui.service_case.plural') }}</span>
                </a>
            </li>
            <li class="nav-item">
                <a @class(['nav-link', 'active' => request()->routeIs('approvals.*')]) href="{{ route('approvals.index') }}" title="Approvals">
                    <i class="bi bi-check2-square nav-icon me-2"></i>
                    <span class="nav-label">Approvals</span>
                </a>
            </li>
            <li class="nav-item">
                <a @class(['nav-link', 'active' => request()->routeIs('refunds.*')]) href="{{ route('refunds.index') }}" title="Refunds">
                    <i class="bi bi-currency-exchange nav-icon me-2"></i>
                    <span class="nav-label">Refunds</span>
                </a>
            </li>
            <li class="nav-item">
                @can('viewAny', App\Models\LeaveRequest::class)
                    <a @class(['nav-link', 'active' => request()->routeIs('leave-requests.*')]) href="{{ route('leave-requests.index') }}" title="Leave Requests">
                        <i class="bi bi-calendar-x nav-icon me-2"></i>
                        <span class="nav-label">Leave Requests</span>
                    </a>
                @endcan
            </li>
            <li class="nav-item">
                @if(auth()->user()?->hasAnyRole(\Database\Seeders\RolePermissionSeeder::SUPPORT_TEAM_ROLES) || auth()->user()?->hasRole(\Database\Seeders\RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST) || auth()->user()?->hasRole(\Database\Seeders\RolePermissionSeeder::ROLE_HARDWARE_TEAM))
                    <a @class(['nav-link', 'active' => request()->routeIs('my-performance.*')]) href="{{ route('my-performance.index') }}" title="Your Performance">
                        <i class="bi bi-bar-chart nav-icon me-2"></i>
                        <span class="nav-label">Your Performance</span>
                    </a>
                @endif
            </li>
        </ul>

        @if(auth()->user()?->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]))
            <div class="nav-section"><span class="nav-label">Administration</span></div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    @can('operations-dashboard.view')
                        <a @class(['nav-link', 'active' => request()->routeIs('admin.operations.index', 'admin.operations.live')]) href="{{ route('admin.operations.index') }}" title="Operations Control Center">
                            <i class="bi bi-sliders nav-icon me-2"></i>
                            <span class="nav-label">Operations</span>
                        </a>
                    @endcan
                </li>
                <li class="nav-item">
                    @can('automation-operations.view')
                        <a @class(['nav-link', 'active' => request()->routeIs('admin.operations.automation-health*')]) href="{{ route('admin.operations.automation-health') }}" title="Automation Health">
                            <i class="bi bi-heart-pulse nav-icon me-2"></i>
                            <span class="nav-label">Automation Health</span>
                        </a>
                    @endcan
                </li>
                <li class="nav-item">
                    @can('automation-operations.view')
                        <a @class(['nav-link', 'active' => request()->routeIs('admin.automation.*')]) href="{{ route('admin.automation.index') }}" title="Automation Operations">
                            <i class="bi bi-robot nav-icon me-2"></i>
                            <span class="nav-label">Automation</span>
                        </a>
                    @endcan
                </li>
                <li class="nav-item">
                    @can('system-settings.manage')
                        <a @class(['nav-link', 'active' => request()->routeIs('admin.system-settings.*')]) href="{{ route('admin.system-settings.index') }}" title="System Settings">
                            <i class="bi bi-toggles nav-icon me-2"></i>
                            <span class="nav-label">System Settings</span>
                        </a>
                    @endcan
                </li>
                <li class="nav-item">
                    @can('viewAny', App\Models\AuditLog::class)
                        <a @class(['nav-link', 'active' => request()->routeIs('audit-logs.*')]) href="{{ route('audit-logs.index') }}" title="Audit Logs">
                            <i class="bi bi-journal-text nav-icon me-2"></i>
                            <span class="nav-label">Audit Logs</span>
                        </a>
                    @endcan
                </li>
                <li class="nav-item">
                    @can('viewAny', App\Models\User::class)
                        <a @class(['nav-link', 'active' => request()->routeIs('users.*')]) href="{{ route('users.index') }}" title="Users">
                            <i class="bi bi-people nav-icon me-2"></i>
                            <span class="nav-label">Users</span>
                        </a>
                    @endcan
                </li>
                <li class="nav-item">
                    @can('team-performance.view')
                        <a @class(['nav-link', 'active' => request()->routeIs('admin.workforce.performance.*')]) href="{{ route('admin.workforce.performance.index') }}" title="Team Performance">
                            <i class="bi bi-graph-up nav-icon me-2"></i>
                            <span class="nav-label">Team Performance</span>
                        </a>
                    @endcan
                </li>
                <li class="nav-item">
                    @can('viewAny', App\Models\CompanyHoliday::class)
                        <a @class(['nav-link', 'active' => request()->routeIs('admin.workforce.holidays.*')]) href="{{ route('admin.workforce.holidays.index') }}" title="Company Holidays">
                            <i class="bi bi-calendar-event nav-icon me-2"></i>
                            <span class="nav-label">Holidays</span>
                        </a>
                    @endcan
                </li>
                @if(auth()->user()?->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN))
                    <li class="nav-item">
                        @can('viewAny', App\Models\SettingProduct::class)
                            <a @class(['nav-link', 'active' => request()->routeIs('settings.*')]) href="{{ route('settings.index') }}" title="Application Settings">
                                <i class="bi bi-gear nav-icon me-2"></i>
                                <span class="nav-label">Application Settings</span>
                            </a>
                        @endcan
                    </li>
                @endif
            </ul>

            <div class="nav-section"><span class="nav-label">Cashfree</span></div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    @can('viewAny', App\Models\CashfreeWebhookLog::class)
                        <a @class(['nav-link', 'active' => request()->routeIs('cashfree.webhook-explorer.*')]) href="{{ route('cashfree.webhook-explorer.index') }}" title="Webhook Explorer">
                            <i class="bi bi-broadcast nav-icon me-2"></i>
                            <span class="nav-label">Webhook Explorer</span>
                        </a>
                    @endcan
                </li>
            </ul>
        @endif
    </nav>

    @include('layouts.partials.version-footer')
</aside>
