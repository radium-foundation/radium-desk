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
          <h2 class="h5 mb-3">System Health</h2>

          <div class="card border-0 shadow-sm">
              <div class="card-body">
                  <div class="row g-3 align-items-center mb-3">
                      <div class="col-md-4">
                          <div class="text-muted small">Platform Status</div>
                          <div class="d-flex align-items-center gap-2 mt-1">
                              <span aria-hidden="true">{{ ($systemHealthSummary['platform_healthy'] ?? false) ? '✓' : '!' }}</span>
                              <strong>{{ $systemHealthSummary['platform_status_label'] ?? 'Unavailable' }}</strong>
                          </div>
                          @if($systemHealthSummary['waiting_for_refresh'] ?? false)
                              <div class="text-muted small mt-1">Waiting for background refresh</div>
                          @elseif(! ($systemHealthSummary['platform_available'] ?? false))
                              <div class="text-muted small mt-1">Unavailable — open Platform Dashboard</div>
                          @endif
                      </div>
                      <div class="col-md-4">
                          <div class="text-muted small">Integration Status</div>
                          <div class="d-flex align-items-center gap-2 mt-1">
                              @php
                                  $integrationStatus = (string) ($systemHealthSummary['integration_status'] ?? 'unknown');
                                  $integrationReady = (bool) ($systemHealthSummary['integration_available'] ?? false);
                              @endphp
                              <span aria-hidden="true">
                                  @if(! $integrationReady)
                                      —
                                  @elseif(in_array($integrationStatus, ['healthy'], true))
                                      ✓
                                  @else
                                      ⚠
                                  @endif
                              </span>
                              <strong>{{ $systemHealthSummary['integration_status_label'] ?? 'Unavailable' }}</strong>
                          </div>
                          @unless($integrationReady)
                              <div class="text-muted small mt-1">Unavailable — waiting for background refresh</div>
                          @endunless
                      </div>
                      <div class="col-md-4">
                          <div class="text-muted small">Last Updated</div>
                          <strong class="d-block mt-1">{{ $systemHealthSummary['last_updated_label'] ?? 'Unavailable' }}</strong>
                      </div>
                  </div>

                  <div class="d-flex flex-wrap gap-2">
                      @can('platform-dashboard.view')
                          <a href="{{ $systemHealthSummary['platform_url'] }}" class="btn btn-sm btn-primary">Open Platform Dashboard</a>
                          <a href="{{ $systemHealthSummary['platform_integrations_url'] }}" class="btn btn-sm btn-outline-primary">Open Integration Health</a>
                      @endcan
                      <a href="{{ $systemHealthSummary['settings_url'] }}" class="btn btn-sm btn-outline-secondary">Open System Settings</a>
                  </div>
              </div>
          </div>
      </div>
  @endcan
@endsection
