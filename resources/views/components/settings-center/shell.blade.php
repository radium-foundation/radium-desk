@props([
    'title' => 'Settings',
    'subtitle' => 'Manage platform configuration and operational behaviour.',
])

<div {{ $attributes->merge(['class' => 'settings-center']) }}>
  @include('navigation.administration-workspace-nav', ['active' => 'settings'])

  <x-settings-center.page-header :title="$title" :subtitle="$subtitle">
    <x-slot:actions>
      {{ $actions ?? '' }}
    </x-slot:actions>
  </x-settings-center.page-header>

  <div class="settings-center-layout">
    <x-settings-center.sidebar />

    <div class="settings-center-main">
      {{ $slot }}
    </div>
  </div>
</div>
