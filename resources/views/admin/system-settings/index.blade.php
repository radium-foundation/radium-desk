@extends('layouts.app')

@section('title', 'System Settings')

@section('content')
    @php
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
    @endphp

    <x-settings-center.shell
        title="System Settings"
        subtitle="Manage operational behaviour, integrations and platform configuration."
    >
        <form method="POST"
              action="{{ route('admin.system-settings.update') }}"
              id="system-settings-form"
              data-system-settings-form
              class="system-settings-page settings-center-form">
            @csrf
            @method('PUT')

            <x-system-settings.header
                :last-updated="$lastUpdated"
                :environment="config('app.env')"
                :compact="true"
            />

            <div class="system-settings-sections" data-system-settings-sections>
                @include('admin.system-settings.partials.overview-section')
                @include('admin.system-settings.partials.operational-center-section')
                @include('admin.system-settings.partials.realtime-card')
                @include('admin.system-settings.partials.performance-card')
                @include('admin.system-settings.partials.automation-section')
                @include('admin.system-settings.partials.notifications-section')
                @include('admin.system-settings.partials.diagnostics-section')
                @include('admin.system-settings.partials.advanced-section')
            </div>

            <x-system-settings.save-bar />
            <x-system-settings.audit-drawer :entries="$auditEntries" />
            <x-system-settings.confirm-modal />
        </form>
    </x-settings-center.shell>
@endsection
