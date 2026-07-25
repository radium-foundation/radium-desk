@props([
    'setting',
    'oldSettings' => null,
    'optionLabels' => [],
    'controlWidth' => 'auto',
    'skipSuperadminDebug' => true,
    'pollingInput' => false,
    'readonly' => false,
    'highImpact' => false,
    'impactMessage' => null,
    'affectedModules' => [],
])

@php
    $oldSettings ??= old('settings', []);
    $inputId = 'setting_' . str_replace('.', '_', $setting['key']);
    $fieldName = 'settings[' . $setting['key'] . ']';
    $oldValue = is_array($oldSettings) && array_key_exists($setting['key'], $oldSettings)
        ? $oldSettings[$setting['key']]
        : $setting['value'];
    $isDisabled = ! empty($setting['disabled']);
    $isSuperadminDebug = $setting['key'] === 'realtime.debug_mode';
    $searchText = strtolower($setting['label'] . ' ' . ($setting['description'] ?? '') . ' ' . $setting['key']);
    $displayValue = match ($setting['type']) {
        'boolean' => filter_var($oldValue, FILTER_VALIDATE_BOOLEAN) ? 'Enabled' : 'Disabled',
        default => (string) $oldValue,
    };
@endphp

@if(! ($skipSuperadminDebug && $isSuperadminDebug && ! auth()->user()?->hasRole(\Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN)))
<div @class([
    'system-settings-row',
    'system-settings-row--disabled' => $isDisabled,
    'system-settings-row--readonly' => $readonly,
]) data-setting-row data-setting-searchable="{{ $searchText }}">
    <div class="system-settings-row__main">
        <div class="system-settings-row__text">
            <div class="system-settings-row__title-row">
                <label class="system-settings-row__title" for="{{ $inputId }}">
                    {{ $setting['label'] }}
                </label>
                @if($isSuperadminDebug)
                    <span class="badge text-bg-warning">Superadmin</span>
                @endif
                @if($isDisabled)
                    <span class="badge text-bg-secondary">{{ $setting['key'] === 'performance.notifications.quiet_hours_enabled' ? 'Future' : 'Coming Soon' }}</span>
                @endif
                @if(! empty($setting['description']))
                    <button type="button"
                            class="system-settings-row__info"
                            data-bs-toggle="tooltip"
                            data-bs-placement="top"
                            title="{{ $setting['description'] }}"
                            aria-label="More information about {{ $setting['label'] }}">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                @endif
            </div>

            @if(! empty($setting['description']))
                <p class="system-settings-row__description">{{ $setting['description'] }}</p>
            @endif

            @error('settings.' . $setting['key'])
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="system-settings-row__control" style="{{ $controlWidth !== 'auto' ? 'max-width: ' . $controlWidth : '' }}">
            @if($setting['type'] === 'boolean')
                <div class="form-check form-switch system-settings-row__switch">
                    <input type="hidden" name="{{ $fieldName }}" value="{{ $isDisabled ? (filter_var($oldValue, FILTER_VALIDATE_BOOLEAN) ? '1' : '0') : '0' }}">
                    <input type="checkbox"
                           name="{{ $fieldName }}"
                           value="1"
                           id="{{ $inputId }}"
                           class="form-check-input @error('settings.' . $setting['key']) is-invalid @enderror"
                           @checked(filter_var($oldValue, FILTER_VALIDATE_BOOLEAN))
                           @disabled($isDisabled)
                           @if($highImpact)
                               data-system-settings-high-impact
                               data-setting-label="{{ $setting['label'] }}"
                               data-setting-impact="{{ $impactMessage ?? $setting['description'] }}"
                               data-setting-modules='@json($affectedModules)'
                           @endif>
                </div>
            @elseif($setting['type'] === 'string' && is_array($setting['allowed'] ?? null))
                <select name="{{ $fieldName }}"
                        id="{{ $inputId }}"
                        class="form-select form-select-sm @error('settings.' . $setting['key']) is-invalid @enderror"
                        @disabled($isDisabled)>
                    @foreach($setting['allowed'] as $option)
                        <option value="{{ $option }}" @selected((string) $oldValue === (string) $option) @disabled($option === 'reverb')>
                            {{ $optionLabels[$option] ?? ucfirst($option) }}@if($option === 'reverb') (Coming Soon)@endif
                        </option>
                    @endforeach
                </select>
            @else
                <div class="system-settings-row__numeric">
                    <div class="input-group input-group-sm">
                        <input type="{{ $setting['type'] === 'integer' ? 'number' : 'text' }}"
                               name="{{ $fieldName }}"
                               id="{{ $inputId }}"
                               value="{{ $oldValue }}"
                               class="form-control @error('settings.' . $setting['key']) is-invalid @enderror"
                               @if($pollingInput) data-performance-polling-input data-setting-key="{{ $setting['key'] }}" @endif
                               @if(($setting['min'] ?? null) !== null) min="{{ $setting['min'] }}" data-setting-min="{{ $setting['min'] }}" @endif
                               @if(($setting['max'] ?? null) !== null) max="{{ $setting['max'] }}" data-setting-max="{{ $setting['max'] }}" @endif
                               @readonly($readonly)
                               @disabled($isDisabled && $setting['type'] !== 'integer')>
                        @if(! empty($setting['unit']))
                            <span class="input-group-text">{{ $setting['unit'] }}</span>
                        @endif
                    </div>
                    @if($setting['type'] === 'integer' && ($setting['min'] ?? null) !== null && ($setting['max'] ?? null) !== null)
                        <input type="range"
                               class="system-settings-row__slider"
                               aria-label="{{ $setting['label'] }} slider"
                               min="{{ $setting['min'] }}"
                               max="{{ $setting['max'] }}"
                               value="{{ $oldValue }}"
                               data-setting-slider-for="{{ $inputId }}"
                               @disabled($isDisabled || $readonly)>
                    @endif
                </div>
            @endif

            @if($isDisabled && $setting['type'] !== 'boolean')
                <input type="hidden" name="{{ $fieldName }}" value="{{ $oldValue }}">
            @endif
        </div>
    </div>

    <span class="visually-hidden" data-setting-audit-value>{{ $displayValue }}</span>
</div>
@endif
