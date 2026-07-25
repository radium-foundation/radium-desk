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
      <div id="administration-integrations" class="card border-0 shadow-sm">
          <div class="card-body">
              @include('admin.hubs.partials.placeholder-card', [
                  'icon' => 'bi-plug',
                  'title' => 'Integrations',
                  'description' => 'Central integration health and configuration hub.',
              ])
          </div>
      </div>
  @endcan
@endsection
