@extends('layouts.app')

@section('title', 'Administration')

@section('content')
  @php
      $canManageUsers = Gate::check('viewAny', App\Models\User::class);
      $canManageSystemSettings = Gate::check('viewAny', App\Models\SystemSetting::class);
      $canManageApplicationSettings = Gate::check('viewAny', App\Models\SettingProduct::class);
      $canViewAuditLogs = Gate::check('viewAny', App\Models\AuditLog::class);
      $visibleCards = (int) $canManageUsers * 2
          + (int) $canManageSystemSettings * 2
          + (int) $canManageApplicationSettings
          + (int) $canViewAuditLogs;
  @endphp

  <div class="mb-4">
      <h1 class="h3 mb-1">Administration</h1>
      <p class="text-muted mb-0">Users, access, system configuration, audit, and application settings.</p>
  </div>

  @if($visibleCards === 0)
      <div class="card border-0 shadow-sm">
          <div class="card-body text-muted">No administration areas are available for your account.</div>
      </div>
  @else
      <div class="row g-4">
          @can('viewAny', App\Models\User::class)
              <div class="col-md-6 col-lg-4">
                  @include('admin.hubs.partials.link-card', [
                      'href' => route('users.index'),
                      'icon' => 'bi-people',
                      'title' => 'Users',
                      'description' => 'Manage team members, admins, and account access.',
                  ])
              </div>
          @endcan

          @can('viewAny', App\Models\User::class)
              <div class="col-md-6 col-lg-4">
                  @include('admin.hubs.partials.link-card', [
                      'href' => route('users.index'),
                      'icon' => 'bi-shield-check',
                      'title' => 'Roles & Access',
                      'description' => 'Assign roles and permissions on user accounts.',
                  ])
              </div>
          @endcan

          @can('viewAny', App\Models\SystemSetting::class)
              <div class="col-md-6 col-lg-4">
                  @include('admin.hubs.partials.link-card', [
                      'href' => route('admin.system-settings.index'),
                      'icon' => 'bi-toggles',
                      'title' => 'System Settings',
                      'description' => 'Operational feature flags and integration toggles.',
                  ])
              </div>
          @endcan

          @can('viewAny', App\Models\SettingProduct::class)
              <div class="col-md-6 col-lg-4">
                  @include('admin.hubs.partials.link-card', [
                      'href' => route('settings.index'),
                      'icon' => 'bi-gear',
                      'title' => 'Application Settings',
                      'description' => 'Configure products, assignment, SLA, and search behavior.',
                  ])
              </div>
          @endcan

          @can('viewAny', App\Models\AuditLog::class)
              <div class="col-md-6 col-lg-4">
                  @include('admin.hubs.partials.link-card', [
                      'href' => route('audit-logs.index'),
                      'icon' => 'bi-journal-text',
                      'title' => 'Audit Logs',
                      'description' => 'Review system activity and record changes.',
                  ])
              </div>
          @endcan

          @can('viewAny', App\Models\SystemSetting::class)
              <div class="col-md-6 col-lg-4">
                  @include('admin.hubs.partials.placeholder-card', [
                      'icon' => 'bi-plug',
                      'title' => 'Integrations',
                      'description' => 'Central integration health and configuration hub.',
                  ])
              </div>
          @endcan
      </div>
  @endif
@endsection
