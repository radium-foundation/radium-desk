@props(['active' => 'items'])

<nav class="workspace-nav mb-4" aria-label="Service master">
    <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto">
        <li class="nav-item">
            <a @class(['nav-link', 'active' => $active === 'items']) href="{{ route('services.items.index') }}">Service items</a>
        </li>
        <li class="nav-item">
            <a @class(['nav-link', 'active' => $active === 'categories']) href="{{ route('services.categories.index') }}">Categories</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="{{ route('service-pos.counter.create') }}">Service counter</a>
        </li>
    </ul>
</nav>
