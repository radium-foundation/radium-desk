@props([
    'members' => [],
])

<section class="mb-4" aria-labelledby="team-telegram-status-heading">
    <h2 id="team-telegram-status-heading" class="h5 mb-3">Team Telegram Status</h2>

    @if($members === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted small mb-0">No active team members found.</div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <ul class="list-group list-group-flush">
                @foreach($members as $member)
                    <li class="list-group-item d-flex justify-content-between align-items-center px-3 py-2">
                        <span class="fw-semibold">{{ $member['name'] }}</span>
                        @if($member['connected'] ?? false)
                            <span class="text-success small">✓ Connected</span>
                        @else
                            <span class="text-warning small">⚠ Not connected</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
