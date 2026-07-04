<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Radium Box Support')</title>

    @vite(['resources/css/app.css', 'resources/js/customer-portal.js'])
</head>
<body class="bg-light">
    <div class="min-vh-100 d-flex align-items-center justify-content-center p-3">
        <div class="customer-portal-card w-100">
            <div class="text-center mb-4">
                <h1 class="h4 fw-bold text-primary mb-1">Radium Box</h1>
                <p class="text-muted small mb-0">Technical Support</p>
            </div>

            @yield('content')
        </div>
    </div>
</body>
</html>
