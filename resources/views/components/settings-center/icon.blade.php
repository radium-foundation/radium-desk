@props([
    'name',
    'class' => 'settings-center-icon',
])

{!! \App\Support\Settings\SettingsIcon::render($name, $class) !!}
