<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'Radium Service Desk'))</title>

    <script>
        (function () {
            try {
                if (localStorage.getItem('radium.sidebarExpanded') === 'true') {
                    document.documentElement.classList.add('sidebar-expanded');
                }
            } catch (error) {
                // Ignore storage access errors.
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script type="application/json" id="workspace-context-slugs">@json(config('workspace.contexts'))</script>
</head>
<body>
    <div class="app-wrapper">
        @include('layouts.partials.sidebar')

        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <div class="app-main">
            @include('layouts.partials.navbar')

            <main class="app-content">
                @include('layouts.partials.flash')

                @yield('content')
            </main>
        </div>
    </div>

    @include('workspace.partials.workspace-modal-host')
    @include('layouts.partials.keyboard-shortcuts-modal')

    @stack('scripts')
</body>
</html>
