@extends('layouts.app')

@section('title', 'Automation Health')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Automation Health</h1>
        <p class="text-muted mb-0">Monitor automation platform health from the unified execution ledger.</p>
    </div>

    @include('navigation.super-admin-workspace-nav', ['active' => 'automation'])

    @include('admin.automation-health.partials.dashboard-body', ['dashboard' => $dashboard])

    @include('admin.automation-health.partials.detail-drawer')
@endsection
