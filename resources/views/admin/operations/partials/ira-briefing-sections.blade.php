@props([
    'formatted' => null,
    'compact' => false,
])

@if($formatted !== null)
    <div @class(['operations-ira-executive', 'operations-ira-executive--compact' => $compact])>
        <p class="fw-semibold mb-2">{{ $formatted->greeting }}</p>

        <div class="mb-2">
            <h3 class="h6 text-muted text-uppercase small mb-1">Operations</h3>
            <ul class="mb-0 ps-3 operations-ira-insight-list">
                @foreach($formatted->operationsLines as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>

        <div class="mb-2">
            <h3 class="h6 text-muted text-uppercase small mb-1">Team</h3>
            @if($formatted->teamPresenceCollecting)
                <p class="mb-0 small text-muted">Presence data collecting</p>
            @else
                <ul class="mb-0 ps-3 operations-ira-insight-list">
                    @foreach($formatted->teamLines as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if($formatted->attentionLines !== [])
            <div class="mb-2">
                <h3 class="h6 text-muted text-uppercase small mb-1">Attention</h3>
                <ul class="mb-0 ps-3 operations-ira-insight-list">
                    @foreach($formatted->attentionLines as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($formatted->suggestion !== null)
            <div>
                <h3 class="h6 text-muted text-uppercase small mb-1">Ira Suggestion</h3>
                <p class="mb-0 small fw-medium">{{ $formatted->suggestion }}</p>
            </div>
        @endif
    </div>
@endif
