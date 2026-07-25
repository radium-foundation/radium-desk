@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    @php
        $activeTab = request('tab', 'general');
        $applicationTabs = [
            'products' => 'Service Cases',
            'device-models' => 'Models',
            'sources' => 'Sources',
            'assignment' => 'Assignment',
            'sla' => 'SLA',
            'search' => 'Search',
        ];
    @endphp

    <x-settings-center.shell
        title="Settings"
        subtitle="Configure application behaviour, integrations, and platform controls."
    >
        @if(in_array($activeTab, array_keys($applicationTabs), true))
            <x-settings-center.application-subnav :tabs="$applicationTabs" :active="$activeTab" />
        @endif

        <div class="settings-center-content">
            @if($activeTab === 'general')
                @include('settings.partials.general')
            @elseif($activeTab === 'products')
                @include('settings.partials.products')
            @elseif($activeTab === 'device-models')
                @include('settings.partials.device-models')
            @elseif($activeTab === 'sources')
                @include('settings.partials.sources')
            @elseif($activeTab === 'assignment')
                @include('settings.partials.assignment')
            @elseif($activeTab === 'notifications')
                @include('settings.partials.notifications')
            @elseif($activeTab === 'sla')
                @include('settings.partials.sla')
            @elseif($activeTab === 'search')
                @include('settings.partials.search')
            @endif
        </div>
    </x-settings-center.shell>
@endsection
