<div class="modal fade"
     id="whatsNewModal"
     tabindex="-1"
     aria-labelledby="whatsNewModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 mb-0" id="whatsNewModalLabel">What's New</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-3">
                <p class="text-muted small mb-3">
                    {{ config('app.name', 'Radium Desk') }} v{{ config('app.version', '0.0.0') }}
                </p>

                @forelse($changelogEntries as $entry)
                    <section @class(['mb-4' => ! $loop->last])>
                        <h3 class="h6 fw-semibold mb-2">{{ $entry['title'] }}</h3>
                        @if($entry['items'] !== [])
                            <ul class="small mb-0 ps-3">
                                @foreach($entry['items'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @empty
                    <p class="text-muted small mb-0">Release notes are not available yet.</p>
                @endforelse
            </div>
            <div class="modal-footer py-2">
                <a href="{{ route('changelog.index') }}" class="btn btn-sm btn-outline-secondary">View full changelog</a>
                <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
