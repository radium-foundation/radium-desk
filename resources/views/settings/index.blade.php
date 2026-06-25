@extends('layouts.app')

@section('title', 'System Settings')

@section('content')
    @php($activeTab = request('tab', 'general'))

    <div class="mb-4">
        <h1 class="h3 mb-1">System Settings</h1>
        <p class="text-muted mb-0">Configure application behavior without code changes.</p>
    </div>

    <div class="row g-4">
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm">
                <div class="list-group list-group-flush rounded-3">
                    @foreach([
                        'general' => 'General',
                        'products' => 'Service Cases',
                        'sources' => 'Sources',
                        'assignment' => 'Assignment',
                        'notifications' => 'Notifications',
                        'sla' => 'SLA',
                        'search' => 'Search',
                    ] as $tabKey => $tabLabel)
                        <a href="{{ route('settings.index', ['tab' => $tabKey]) }}"
                           @class(['list-group-item list-group-item-action', 'active' => $activeTab === $tabKey])>
                            {{ $tabLabel }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            @if($activeTab === 'general')
                @include('settings.partials.general')
            @elseif($activeTab === 'products')
                @include('settings.partials.products')
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
    </div>
@endsection
