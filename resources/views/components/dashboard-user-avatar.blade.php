@props([
    'user',
    'ariaPrefix' => 'User',
])

@php
    $automationIdentity = app(\App\Services\AutomationIdentityService::class);

    if ($automationIdentity->isAutomationActor($user)) {
        $initial = 'I';
        $displayName = (string) config('automation.display_name', 'Ira');
        $subtitle = (string) config('automation.subtitle', 'IRA AI');
        $tooltipHtml = e($displayName).'<br>'.e($subtitle);
        $ariaLabel = "{$ariaPrefix}: {$displayName}, {$subtitle}";
    } else {
        $displayName = trim((string) $user->name) ?: $user->firstName();
        $initial = $displayName !== '' ? strtoupper(substr($displayName, 0, 1)) : null;
        $tooltipHtml = e($displayName);
        $ariaLabel = "{$ariaPrefix}: {$displayName}";
    }
@endphp

@if($initial)
    <span class="dashboard-u-avatar"
          data-bs-toggle="tooltip"
          data-bs-placement="top"
          data-bs-html="true"
          data-bs-title="{!! $tooltipHtml !!}"
          aria-label="{{ $ariaLabel }}">{{ $initial }}</span>
@else
    —
@endif
