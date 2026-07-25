@props([
    'entries' => [],
])

<aside id="system-settings-audit-drawer"
       class="system-settings-audit-drawer"
       data-system-settings-audit-drawer
       aria-labelledby="system-settings-audit-title"
       aria-hidden="true"
       hidden>
    <div class="system-settings-audit-drawer__backdrop" data-system-settings-audit-close tabindex="-1"></div>
    <div class="system-settings-audit-drawer__panel" role="dialog">
        <header class="system-settings-audit-drawer__header">
            <div>
                <h2 id="system-settings-audit-title" class="system-settings-audit-drawer__title">Audit History</h2>
                <p class="system-settings-audit-drawer__subtitle">Recent changes to system settings.</p>
            </div>
            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-system-settings-audit-close
                    aria-label="Close audit history">
                <x-settings-center.icon name="x" class="settings-center-icon settings-center-icon--sm" />
            </button>
        </header>

        <div class="system-settings-audit-drawer__body">
            @forelse($entries as $entry)
                <article class="system-settings-audit-entry">
                    <div class="system-settings-audit-entry__meta">
                        <time datetime="{{ $entry['updated_at']->toIso8601String() }}">
                            {{ $entry['updated_at']->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                        </time>
                        @if($entry['updated_by_name'])
                            <span class="system-settings-audit-entry__user">{{ $entry['updated_by_name'] }}</span>
                        @endif
                    </div>
                    <div class="system-settings-audit-entry__setting">{{ $entry['label'] }}</div>
                    <div class="system-settings-audit-entry__value">
                        <span class="system-settings-audit-entry__current">{{ $entry['value'] }}</span>
                    </div>
                </article>
            @empty
                <p class="system-settings-audit-drawer__empty">No setting changes recorded yet.</p>
            @endforelse
        </div>
    </div>
</aside>
