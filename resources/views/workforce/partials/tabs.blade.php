@props([
    'tabs' => [],
    'activeTab' => 'overview',
    'baseUrl' => null,
])

@php
    $baseUrl ??= url()->current();
@endphp

<ul class="nav nav-tabs workforce360-tabs mb-4" role="tablist">
    @foreach($tabs as $tab)
        @php
            $key = $tab['key'] ?? 'overview';
            $href = $tab['href'] ?? ($baseUrl . '?' . http_build_query(['tab' => $key]));
            $isActive = $activeTab === $key;
        @endphp
        <li class="nav-item" role="presentation">
            <a
                @class(['nav-link', 'active' => $isActive])
                href="{{ $href }}"
                @if($isActive) aria-current="page" @endif
            >
                {{ $tab['label'] ?? ucfirst($key) }}
            </a>
        </li>
    @endforeach
</ul>
