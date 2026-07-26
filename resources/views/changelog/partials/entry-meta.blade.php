<dl class="changelog-entry-meta small text-muted mb-2">
    <div class="d-flex flex-wrap gap-3">
        <div>
            <dt class="d-inline fw-semibold">Version:</dt>
            <dd class="d-inline mb-0">{{ $entry['version'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="d-inline fw-semibold">Release date:</dt>
            <dd class="d-inline mb-0">{{ $entry['release_date'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="d-inline fw-semibold">Environment:</dt>
            <dd class="d-inline mb-0">{{ $entry['environment'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="d-inline fw-semibold">Git commit:</dt>
            <dd class="d-inline mb-0">{{ $entry['git_commit'] ?? '—' }}</dd>
        </div>
    </div>
</dl>
