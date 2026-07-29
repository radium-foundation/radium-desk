@extends('layouts.app')

@section('title', 'Administration')

@section('content')
  <div class="mb-4">
      <h1 class="h3 mb-1">Administration</h1>
      <p class="text-muted mb-0">Users, access, configuration, holidays, and integrations.</p>
  </div>

  @include('navigation.administration-workspace-nav', ['active' => 'overview'])

  <div class="card border-0 shadow-sm mb-4">
      <div class="card-body text-muted">
          Use the workspace tabs above to manage users, settings, holidays, and integrations.
      </div>
  </div>

  @can('viewAny', App\Models\SystemSetting::class)
      <div id="administration-integrations" class="mb-4">
          <div id="administration-api-health">
              <h2 class="h5 mb-3">API Health</h2>

              <div class="card border-0 shadow-sm mb-4">
                  <div class="card-body py-3">
                      <div class="operations-integration-grid" role="list" aria-label="Integration status">
                          @foreach($integrationCards as $card)
                              @php
                                  $status = (string) ($card['status'] ?? 'healthy');
                                  $statusClassMap = [
                                      'healthy' => 'healthy',
                                      'warning' => 'warning',
                                      'failed' => 'danger',
                                  ];
                                  $isHealthy = $status === 'healthy';
                              @endphp
                              <div
                                  @class([
                                      'operations-integration-pill',
                                      'operations-integration-pill--' . ($statusClassMap[$status] ?? 'info'),
                                      'operations-integration-pill--issue' => ! $isHealthy,
                                  ])
                                  role="listitem"
                                  title="{{ $card['detail'] ?? '' }}"
                              >
                                  <span class="operations-integration-pill-icon" aria-hidden="true">{{ $isHealthy ? '✓' : '!' }}</span>
                                  <span class="operations-integration-pill-label">{{ $card['label'] }}</span>
                              </div>
                          @endforeach
                      </div>
                  </div>
              </div>

              @include('admin.operations.partials.gmail-health', [
                  'health' => $gmailHealth,
                  'showActions' => true,
              ])
          </div>
      </div>
  @endcan
@endsection
