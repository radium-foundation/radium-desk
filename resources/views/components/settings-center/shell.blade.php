@props([
    'title' => 'Settings',
    'subtitle' => 'Manage platform configuration and operational behaviour.',
    'workspaceNav' => 'administration',
    'workspaceActive' => 'settings',
])

<div {{ $attributes->merge(['class' => 'settings-center']) }}>
    @if($workspaceNav === 'mission-control')
        @include('navigation.mission-control-workspace-nav', ['active' => $workspaceActive])
    @elseif($workspaceNav === 'administration')
        @include('navigation.administration-workspace-nav', ['active' => $workspaceActive])
    @endif

    <x-settings-center.page-header :title="$title" :subtitle="$subtitle">
        <x-slot:actions>
            {{ $actions ?? '' }}
        </x-slot:actions>
    </x-settings-center.page-header>

    <div class="settings-center-layout">
        @isset($sidebar)
            {{ $sidebar }}
        @else
            <x-settings-center.sidebar />
        @endisset

        <div class="settings-center-main">
            {{ $slot }}
        </div>
    </div>
</div>
