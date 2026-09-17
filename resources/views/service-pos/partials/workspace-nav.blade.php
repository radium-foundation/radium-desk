@props(['active' => 'counter'])

<nav class="workspace-nav mb-4" aria-label="Service POS">
    <ul class="nav nav-tabs workspace-nav-tabs">
        <li class="nav-item"><a @class(['nav-link', 'active' => $active === 'counter']) href="{{ route('service-pos.counter.create') }}">Counter</a></li>
        <li class="nav-item"><a class="nav-link" href="{{ route('services.items.index') }}">Service master</a></li>
        <li class="nav-item"><a class="nav-link" href="{{ route('finance.receivables.index') }}">Receivables</a></li>
    </ul>
</nav>
