@php
    use Database\Seeders\RolePermissionSeeder;
@endphp

<aside class="app-sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="brand d-flex align-items-center px-3">
        <i class="bi bi-headset text-primary nav-icon fs-5"></i>
        <span class="brand-text text-white fw-semibold ms-2">Radium</span>
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
        </ul>

        @if(auth()->user()?->hasAnyRole([RolePermissionSeeder::ROLE_ADMIN, RolePermissionSeeder::ROLE_SUPERADMIN]))
            <div class="nav-section"><span class="nav-label">Administration</span></div>
            <ul class="nav flex-column">
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
                @if(auth()->user()?->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN))
                    <li class="nav-item">
                        @can('viewAny', App\Models\SettingProduct::class)
                            <a @class(['nav-link', 'active' => request()->routeIs('settings.*')]) href="{{ route('settings.index') }}" title="Settings">
                                <i class="bi bi-gear nav-icon me-2"></i>
                                <span class="nav-label">Settings</span>
                            </a>
                        @endcan
                    </li>
                @endif
            </ul>
        @endif
    </nav>
</aside>
