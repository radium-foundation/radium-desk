@php
    $entries = $sync_history ?? [];
@endphp

@if(count($entries) > 0)
    <section class="customer-360-sync-history" aria-labelledby="customer-360-sync-history-heading">
        <h4 class="customer-360-sync-history-title" id="customer-360-sync-history-heading">Synchronization history</h4>
        <ol class="customer-360-sync-history-list">
            @foreach($entries as $entry)
                @php
                    $icon = match ($entry['icon'] ?? '') {
                        'success' => '✓',
                        'manual', 'scheduler' => '↻',
                        default => null,
                    };
                @endphp
                <li class="customer-360-sync-history-item">
                    <div class="customer-360-sync-history-event">
                        @if($icon !== null)
                            <span class="customer-360-sync-history-icon" aria-hidden="true">{{ $icon }}</span>
                        @endif
                        <span class="customer-360-sync-history-label">{{ $entry['title'] ?? '' }}</span>
                    </div>
                    <div class="customer-360-sync-history-meta">
                        @if(filled($entry['subtitle'] ?? null))
                            <span class="customer-360-sync-history-subtitle">{{ $entry['subtitle'] }}</span>
                        @else
                            <span class="customer-360-sync-history-date">{{ $entry['date'] ?? '' }}</span>
                            <span class="customer-360-sync-history-time">{{ $entry['time'] ?? '' }}</span>
                        @endif
                        @if(filled($entry['actor_name'] ?? null))
                            <span class="customer-360-sync-history-actor">{{ $entry['actor_name'] }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
@endif
