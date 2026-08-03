@extends('layouts.app')

@section('title', 'Administration')

@section('content')
  <div class="mb-4">
      <h1 class="h3 mb-1">Administration</h1>
      <p class="text-muted mb-0">Users, access, configuration, and holidays. Live monitoring lives on Platform.</p>
  </div>

  @include('navigation.administration-workspace-nav', ['active' => 'overview'])

  <div class="card border-0 shadow-sm mb-4">
      <div class="card-body text-muted">
          Use the workspace tabs above to manage users, settings, and holidays.
      </div>
  </div>

  @can('viewAny', App\Models\SystemSetting::class)
      <div id="administration-system-health" class="mb-4">
          <h2 class="h5 mb-3">Observe or configure</h2>

          <div class="card border-0 shadow-sm">
              <div class="card-body">
                  <p class="text-muted mb-3">
                      Platform is Mission Control for live health, alerts, and diagnostics.
                      Operational Settings is for day-to-day Desk behaviour.
                  </p>
                  <div class="d-flex flex-wrap gap-2">
                      @can('platform-dashboard.view')
                          <a href="{{ $systemHealthSummary['platform_url'] }}" class="btn btn-sm btn-primary">Open Platform Dashboard</a>
                          <a href="{{ $systemHealthSummary['platform_integrations_url'] }}" class="btn btn-sm btn-outline-primary">Open Integration Health</a>
                      @endcan
                      <a href="{{ $systemHealthSummary['settings_url'] }}" class="btn btn-sm btn-outline-secondary">Open Operational Settings</a>
                      @can('managePlatformConfiguration')
                          <a href="{{ route('admin.platform-configuration.index') }}" class="btn btn-sm btn-outline-secondary">Open Platform Configuration</a>
                      @endcan
                  </div>
              </div>
          </div>
      </div>
  @endcan
@endsection
