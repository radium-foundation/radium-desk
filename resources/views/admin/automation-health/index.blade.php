@extends('layouts.app')

@section('title', 'Automation Health')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Automation Health</h1>
        <p class="text-muted mb-0">Monitor automation platform health from the unified execution ledger.</p>
    </div>

    @include('admin.automation-health.partials.overview-cards', ['overview' => $dashboard['overview']])
    @include('admin.automation-health.partials.breakdown', ['breakdown' => $dashboard['breakdown']])
    @include('admin.automation-health.partials.filters', [
        'filterOptions' => $dashboard['filter_options'],
        'filters' => $dashboard['filters'],
    ])
    @include('admin.automation-health.partials.activity-table', ['activity' => $dashboard['activity']])
    @include('admin.automation-health.partials.failures', ['failures' => $dashboard['failures']])
    @include('admin.automation-health.partials.detail-drawer')
@endsection
