@props([
    'active' => 'counter',
])

@php
    $tabs = [
        'counter' => ['label' => 'Counter', 'url' => route('pos.counter.create')],
        'sales' => ['label' => 'Sales', 'url' => route('pos.sales.index')],
    ];
@endphp

<nav class="workspace-nav mb-4" aria-label="POS workspace">
    <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
        @foreach($tabs as $key => $tab)
            <li class="nav-item" role="presentation">
                <a @class(['nav-link', 'active' => $active === $key]) href="{{ $tab['url'] }}" @if($active === $key) aria-current="page" @endif>
                    {{ $tab['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
