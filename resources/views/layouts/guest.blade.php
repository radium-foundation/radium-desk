<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Login') — {{ config('app.name', 'Radium Desk') }}</title>

    @include('layouts.partials.head-meta')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-light">
    <div class="min-vh-100 d-flex align-items-center justify-content-center p-3">
        <div class="auth-card">
            <div class="text-center mb-4">
                @php($branding = app(\App\Services\BrandingService::class))
                @if($branding->hasLogo())
                    <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->companyName() }}" class="guest-brand-logo mb-2">
                @else
                    <i class="bi bi-headset text-primary fs-2 d-block mb-2" aria-hidden="true"></i>
                @endif
                <h1 class="h4 fw-bold text-primary mb-1">{{ $branding->appName() }}</h1>
                <p class="text-muted small mb-0">Internal operations portal</p>
            </div>

            @yield('content')
        </div>
    </div>

    @include('layouts.partials.whats-new-modal')

    <div class="position-fixed bottom-0 start-0 end-0 text-center pb-3">
        @include('layouts.partials.version-footer')
    </div>
</body>
</html>
