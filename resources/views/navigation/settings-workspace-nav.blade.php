@props([
    'active' => 'operational',
])

@php
    use App\Models\SettingProduct;
    use App\Models\SystemSetting;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Support\Facades\Gate;

    $user = auth()->user();
    $canViewApplicationSettings = $user?->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)
        && Gate::check('viewAny', SettingProduct::class);
    $canViewSystemSettings = Gate::check('viewAny', SystemSetting::class);
    $canManageSystemSettings = $user?->can('system-settings.manage') ?? false;

    $tabs = [];

    if ($canViewApplicationSettings) {
        $tabs['general'] = [
            'label' => 'General',
            'url' => route('settings.index', ['tab' => 'general']),
        ];
        $tabs['application'] = [
            'label' => 'Application',
            'url' => route('settings.index', ['tab' => 'products']),
        ];
    }

    if ($canViewSystemSettings) {
        $tabs['operational'] = [
            'label' => 'Operational',
            'url' => route('admin.system-settings.index'),
        ];
    }

    if ($canViewApplicationSettings) {
        $tabs['notifications'] = [
            'label' => 'Notifications',
            'url' => route('settings.index', ['tab' => 'notifications']),
        ];
    } elseif ($canViewSystemSettings) {
        $tabs['notifications'] = [
            'label' => 'Notifications',
            'url' => route('admin.system-settings.index').'#category-notifications',
        ];
    }

    if ($canViewSystemSettings) {
        $tabs['email'] = [
            'label' => 'Email',
            'url' => route('admin.system-settings.index').'#category-email',
        ];
        $tabs['whatsapp'] = [
            'label' => 'WhatsApp',
            'url' => route('admin.system-settings.index').'#category-whatsapp',
        ];
        $tabs['telephony'] = [
            'label' => 'Telephony',
            'url' => route('admin.system-settings.index').'#category-telegram',
        ];
        $tabs['feature_flags'] = [
            'label' => 'Feature Flags',
            'url' => route('admin.system-settings.index').'#category-system',
        ];
    }

    if ($canManageSystemSettings) {
        $tabs['integrations'] = [
            'label' => 'Integrations',
            'url' => route('admin.administration.index').'#administration-integrations',
        ];
    }
@endphp

@if(count($tabs) > 1)
    <nav class="workspace-nav settings-workspace-nav mb-4" aria-label="Settings workspace">
        <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
            @foreach($tabs as $key => $tab)
                <li class="nav-item" role="presentation">
                    <a
                        @class(['nav-link', 'active' => $active === $key])
                        href="{{ $tab['url'] }}"
                        @if($active === $key) aria-current="page" @endif
                    >
                        {{ $tab['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
