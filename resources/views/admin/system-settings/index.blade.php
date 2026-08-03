@extends('layouts.app')

@section('title', $settingsSurface === 'platform' ? 'Platform Configuration' : 'Operational Settings')

@section('content')
    @php
        $settingsSurface = $settingsSurface ?? 'operational';
        $showPlatformSections = $settingsSurface === 'platform';
        $showOperationalSections = $settingsSurface === 'operational';

        $allSettings = collect($groupedSettings)->flatten(1)
            ->merge($realtimeSettings)
            ->merge($performancePollingSettings)
            ->merge($performanceHybridRealtimeSettings)
            ->merge($performanceNotificationSettings);

        $auditEntries = $allSettings
            ->filter(fn ($s) => ! empty($s['updated_at']))
            ->map(fn ($s) => [
                'key' => $s['key'],
                'label' => $s['label'],
                'value' => match ($s['type'] ?? 'string') {
                    'boolean' => filter_var($s['value'], FILTER_VALIDATE_BOOLEAN) ? 'Enabled' : 'Disabled',
                    default => (string) $s['value'],
                },
                'updated_at' => $s['updated_at'],
                'updated_by_name' => $s['updated_by_name'] ?? null,
            ])
            ->sortByDesc('updated_at')
            ->values();

        $lastUpdated = ($auditEntries->first() ?? [])['updated_at'] ?? null;

        $preservedForOperational = collect()
            ->merge($groupedSettings['system'] ?? [])
            ->merge($groupedSettings['email'] ?? [])
            ->merge($groupedSettings['whatsapp'] ?? [])
            ->merge($groupedSettings['telegram'] ?? [])
            ->merge($groupedSettings['outbox'] ?? [])
            ->merge($performanceHybridRealtimeSettings)
            ->values();
    @endphp

    <x-settings-center.shell
        :title="$showPlatformSections ? 'Platform Configuration' : 'Operational Settings'"
        :subtitle="$showPlatformSections
            ? 'Platform integrations, environment, diagnostics, and advanced configuration.'
            : 'Operational behaviour controls for day-to-day Desk administration.'"
        workspace-nav="administration"
        :workspace-active="$showPlatformSections ? 'platform_configuration' : 'operational_settings'"
    >
        <form method="POST"
              action="{{ route('admin.system-settings.update') }}"
              id="system-settings-form"
              data-system-settings-form
              class="system-settings-page settings-center-form">
            @csrf
            @method('PUT')
            <input type="hidden" name="_settings_surface" value="{{ $settingsSurface }}">

            @if($showPlatformSections)
                <x-system-settings.header
                    :last-updated="$lastUpdated"
                    :environment="config('app.env')"
                    :compact="true"
                    :show-audit="true"
                />
            @else
                <x-system-settings.header :compact="true" :show-audit="false" />
            @endif

            <div class="system-settings-sections" data-system-settings-sections>
                @if($showOperationalSections)
                    @include('admin.system-settings.partials.operational-center-section', [
                        'showPlatformIntegrations' => false,
                    ])
                    @include('admin.system-settings.partials.realtime-card', [
                        'showPlatformLinks' => false,
                    ])
                    @include('admin.system-settings.partials.performance-card', [
                        'showPlatformSections' => false,
                    ])
                    @include('admin.system-settings.partials.automation-section')
                    @include('admin.system-settings.partials.notifications-section')
                    @include('admin.system-settings.partials.preserve-settings-inputs', [
                        'settings' => $preservedForOperational,
                    ])
                @endif

                @if($showPlatformSections)
                    @include('admin.system-settings.partials.overview-section')
                    @include('admin.system-settings.partials.operational-center-section', [
                        'showPlatformIntegrations' => true,
                    ])
                    @include('admin.system-settings.partials.realtime-card', [
                        'showPlatformLinks' => true,
                    ])
                    @include('admin.system-settings.partials.performance-card', [
                        'showPlatformSections' => true,
                    ])
                    @include('admin.system-settings.partials.automation-section')
                    @include('admin.system-settings.partials.notifications-section')
                    @include('admin.system-settings.partials.diagnostics-section')
                    @include('admin.system-settings.partials.advanced-section')
                @endif
            </div>

            <x-system-settings.save-bar />
            @if($showPlatformSections)
                <x-system-settings.audit-drawer :entries="$auditEntries" />
            @endif
            <x-system-settings.confirm-modal />
        </form>
    </x-settings-center.shell>
@endsection

@push('vite')
    @vite('resources/js/pages/system-settings.js')
@endpush
