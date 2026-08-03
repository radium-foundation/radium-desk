@props([
    'settings' => [],
])

@foreach($settings as $setting)
    @php
        $key = $setting['key'] ?? null;
        $type = $setting['type'] ?? 'string';
        $value = old('settings.'.$key, $setting['value'] ?? '');
        if ($type === 'boolean') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
    @endphp
    @continue($key === null || ! empty($setting['disabled']))
    <input type="hidden" name="settings[{{ $key }}]" value="{{ $value }}">
@endforeach
