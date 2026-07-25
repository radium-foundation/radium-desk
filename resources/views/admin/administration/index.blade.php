@extends('layouts.app')

@section('title', 'Administration')

@section('content')
  @php
      $canManageUsers = Gate::check('viewAny', App\Models\User::class);
      $canManageSystemSettings = Gate::check('viewAny', App\Models\SystemSetting::class);
      $canManageApplicationSettings = Gate::check('viewAny', App\Models\SettingProduct::class);
      $canManageHolidays = Gate::check('viewAny', App\Models\CompanyHoliday::class);
      $visibleCards = (int) $canManageUsers * 2
          + (int) $canManageSystemSettings * 2
          + (int) $canManageApplicationSettings
          + (int) $canManageHolidays;
  @endphp

  <div class="mb-4">
      <h1 class="h3 mb-1">Administration</h1>
      <p class="text-muted mb-0">Users, access, system configuration, holidays, and application settings.</p>
  </div>

  @include('navigation.administration-workspace-nav', ['active' => 'overview'])

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

          @can('viewAny', App\Models\CompanyHoliday::class)
              <div class="col-md-6 col-lg-4">
                  @include('admin.hubs.partials.link-card', [
                      'href' => route('admin.workforce.holidays.index'),
                      'icon' => 'bi-calendar-event',
                      'title' => 'Holiday Calendar',
                      'description' => 'Company holidays that block automatic assignment.',
                  ])
              </div>
          @endcan

          @can('viewAny', App\Models\SystemSetting::class)
              <div class="col-md-6 col-lg-4" id="administration-integrations">
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
