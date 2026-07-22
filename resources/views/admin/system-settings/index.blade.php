@extends('layouts.app')

@section('title', 'System Settings')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">System Settings</h1>
        <p class="text-muted mb-0">Operational feature flags and integration toggles for administrators.</p>
    </div>

    <form method="POST" action="{{ route('admin.system-settings.update') }}">
        @csrf
        @method('PUT')

        <div class="vstack gap-4">
            @include('admin.system-settings.partials.realtime-card')
            @include('admin.system-settings.partials.performance-card')

            @foreach($groupedSettings as $categoryKey => $settings)
                @php
                    $category = $categories[$categoryKey] ?? ['label' => ucfirst($categoryKey), 'description' => null, 'icon' => 'bi-sliders'];
                @endphp

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi {{ $category['icon'] ?? 'bi-sliders' }} text-primary"></i>
                            <div>
                                <h2 class="h6 mb-0">{{ $category['label'] ?? ucfirst($categoryKey) }}</h2>
                                @if(! empty($category['description']))
                                    <p class="text-muted small mb-0">{{ $category['description'] }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="vstack gap-3">
                            @foreach($settings as $setting)
                                @php
                                    $inputId = 'setting_' . str_replace('.', '_', $setting['key']);
                                    $fieldName = 'settings[' . $setting['key'] . ']';
                                    $oldSettings = old('settings', []);
                                    $oldValue = is_array($oldSettings) && array_key_exists($setting['key'], $oldSettings)
                                        ? $oldSettings[$setting['key']]
                                        : $setting['value'];
                                @endphp

                                <div class="border rounded p-3 @if(! empty($setting['disabled'])) opacity-75 @endif">
                                    @if($setting['type'] === 'boolean')
                                        <div class="form-check form-switch mb-0">
                                            <input type="hidden" name="{{ $fieldName }}" value="{{ ! empty($setting['disabled']) ? (filter_var($oldValue, FILTER_VALIDATE_BOOLEAN) ? '1' : '0') : '0' }}">
                                            <input type="checkbox"
                                                   name="{{ $fieldName }}"
                                                   value="1"
                                                   id="{{ $inputId }}"
                                                   class="form-check-input @error('settings.' . $setting['key']) is-invalid @enderror"
                                                   @checked(filter_var($oldValue, FILTER_VALIDATE_BOOLEAN))
                                                   @disabled(! empty($setting['disabled']))>
                                            <label class="form-check-label fw-medium" for="{{ $inputId }}">
                                                {{ $setting['label'] }}
                                                @if(! empty($setting['disabled']))
                                                    <span class="badge text-bg-secondary ms-1">Coming Soon</span>
                                                @endif
                                            </label>
                                        </div>
                                    @else
                                        <label class="form-label fw-medium" for="{{ $inputId }}">{{ $setting['label'] }}</label>
                                        <input type="text"
                                               name="{{ $fieldName }}"
                                               id="{{ $inputId }}"
                                               value="{{ $oldValue }}"
                                               class="form-control @error('settings.' . $setting['key']) is-invalid @enderror"
                                               @disabled(! empty($setting['disabled']))>
                                        @if(! empty($setting['disabled']))
                                            <input type="hidden" name="{{ $fieldName }}" value="{{ $oldValue }}">
                                        @endif
                                    @endif

                                    @if(! empty($setting['description']))
                                        <div class="form-text">{{ $setting['description'] }}</div>
                                    @endif

                                    @error('settings.' . $setting['key'])
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror

                                    @if($setting['updated_at'])
                                        <div class="text-muted small mt-2">
                                            Last updated {{ $setting['updated_at']->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                                            @if($setting['updated_by_name'])
                                                by {{ $setting['updated_by_name'] }}
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary">Save System Settings</button>
        </div>
    </form>
@endsection
