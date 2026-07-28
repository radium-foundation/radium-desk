<dl class="changelog-entry-meta small text-muted mb-2">
    <div class="d-flex flex-wrap gap-3">
        <div>
            <dt class="d-inline fw-semibold">Version:</dt>
            <dd class="d-inline mb-0">{{ filled($entry['version'] ?? null) ? $entry['version'] : '—' }}</dd>
        </div>
        <div>
            <dt class="d-inline fw-semibold">Release date:</dt>
            <dd class="d-inline mb-0">{{ filled($entry['release_date'] ?? null) ? $entry['release_date'] : '—' }}</dd>
        </div>
        <div>
            <dt class="d-inline fw-semibold">Environment:</dt>
            <dd class="d-inline mb-0">{{ filled($entry['environment'] ?? null) ? $entry['environment'] : '—' }}</dd>
        </div>
        <div>
            <dt class="d-inline fw-semibold">Git commit:</dt>
            <dd class="d-inline mb-0">{{ filled($entry['git_commit'] ?? null) ? $entry['git_commit'] : '—' }}</dd>
        </div>
    </div>
</dl>
