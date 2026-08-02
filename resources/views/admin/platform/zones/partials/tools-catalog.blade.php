@props([
    'groups' => [],
    'zoneKey' => 'tools',
    'available' => true,
])

<div class="platform-tools-catalog" data-platform-tools-catalog>
    @foreach($groups as $group)
        <div class="mb-4" data-platform-searchable="{{ strtolower(($group['title'] ?? '').' tools') }}">
            <h3 class="h6 text-uppercase text-muted fw-semibold mb-2">{{ $group['title'] ?? 'Tools' }}</h3>
            <div class="d-flex flex-wrap gap-2">
                @foreach(($group['links'] ?? []) as $link)
                    <a href="{{ $link['url'] ?? '#' }}" class="btn btn-sm btn-outline-primary">
                        {{ $link['label'] ?? 'Open' }}
                    </a>
                @endforeach
            </div>
            @if(! empty($group['description']))
                <p class="text-muted small mt-2 mb-0">{{ $group['description'] }}</p>
            @endif
        </div>
    @endforeach

    @if($groups === [])
        <p class="text-muted small mb-0">No diagnostic tools available for your role.</p>
    @endif
</div>
