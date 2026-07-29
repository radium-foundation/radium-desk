@props([
    'health' => [],
    'showActions' => true,
])

<section aria-labelledby="gmail-health-heading" data-gmail-health-card>
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h2 id="gmail-health-heading" class="h5 mb-0">Gmail API Health</h2>
        @php
            $statusClass = match ($health['badge_class'] ?? 'secondary') {
                'success' => 'healthy',
                'danger' => 'danger',
                'warning' => 'warning',
                default => 'info',
            };
        @endphp
        <span @class(['status-badge', 'status-' . $statusClass])>{{ $health['overall_status'] ?? $health['status_label'] ?? 'Unknown' }}</span>
    </div>

    <div class="card border-0 shadow-sm operations-card-hover">
        <div class="card-body">
            <p class="text-muted small mb-3">{{ $health['detail'] ?? 'Gmail inbound synchronization.' }}</p>

            <div class="operations-metric-row mb-3">
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Last Success</span>
                    <strong class="operations-metric-row-value operations-metric-row-value--compact">
                        @if(! empty($health['last_successful_sync_at']))
                            {{ display_app_datetime($health['last_successful_sync_at']) }}
                        @else
                            —
                        @endif
                    </strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Last Attempt</span>
                    <strong class="operations-metric-row-value operations-metric-row-value--compact">
                        @if(! empty($health['last_attempted_sync_at']))
                            {{ display_app_datetime($health['last_attempted_sync_at']) }}
                        @else
                            —
                        @endif
                    </strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Mailbox</span>
                    <strong class="operations-metric-row-value operations-metric-row-value--compact">{{ $health['mailbox'] ?? '—' }}</strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Cursor Lag</span>
                    <strong class="operations-metric-row-value">
                        @if(($health['cursor_lag'] ?? null) !== null)
                            {{ number_format($health['cursor_lag']) }}
                        @else
                            —
                        @endif
                    </strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Latency</span>
                    <strong class="operations-metric-row-value">
                        @if(($health['response_latency_ms'] ?? null) !== null)
                            {{ number_format($health['response_latency_ms']) }} ms
                        @else
                            —
                        @endif
                    </strong>
                </div>
            </div>

            <div class="operations-metric-row mb-3">
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Processed Today</span>
                    <strong class="operations-metric-row-value">{{ number_format($health['messages_processed_today'] ?? 0) }}</strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Failed Today</span>
                    <strong class="operations-metric-row-value">{{ number_format($health['messages_failed_today'] ?? 0) }}</strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Skipped Today</span>
                    <strong class="operations-metric-row-value">{{ number_format($health['messages_skipped_today'] ?? 0) }}</strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Retries Today</span>
                    <strong class="operations-metric-row-value">{{ number_format($health['retry_count_today'] ?? 0) }}</strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">OAuth</span>
                    <strong class="operations-metric-row-value operations-metric-row-value--compact">{{ $health['oauth_status'] ?? '—' }}</strong>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="text-muted small">History cursor</div>
                    <code class="small">{{ $health['history_cursor'] ?? '—' }}</code>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">Profile historyId</div>
                    <code class="small">{{ $health['profile_history_id'] ?? '—' }}</code>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-3 mb-3 small text-muted">
                <span>Scheduler: {{ ($health['scheduler_running'] ?? false) ? 'Running' : 'Stale / unknown' }}</span>
                <span>Queue: {{ ($health['queue_healthy'] ?? true) ? 'Healthy' : 'Failed jobs present' }}</span>
                <span>API quota: {{ $health['api_quota'] ?? 'Unavailable' }}</span>
            </div>

            @if(! empty($health['last_error']))
                <div class="alert alert-warning py-2 small mb-3">
                    <strong>Last error:</strong> {{ $health['last_error'] }}
                </div>
            @endif

            @if($showActions)
                <div class="d-flex flex-wrap gap-2" data-gmail-admin-actions
                     data-sync-url="{{ $health['sync_now_url'] ?? route('admin.gmail.sync-now') }}"
                     data-rebaseline-url="{{ $health['rebaseline_url'] ?? route('admin.gmail.rebaseline') }}"
                     data-mailbox="{{ $health['mailbox'] ?? '' }}">
                    <button type="button" class="btn btn-sm btn-primary" data-gmail-sync-now>Run Gmail Sync Now</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-gmail-rebaseline>Re-baseline Cursor</button>
                    <a href="{{ $health['logs_url'] ?? route('admin.gmail.logs') }}" class="btn btn-sm btn-outline-secondary">View Gmail Sync Logs</a>
                    <a href="{{ $health['failed_messages_url'] ?? route('admin.gmail.failed-messages') }}" class="btn btn-sm btn-outline-secondary">View Failed Messages</a>
                    <span class="small text-muted align-self-center" data-gmail-action-message role="status" aria-live="polite"></span>
                </div>
            @endif
        </div>
    </div>
</section>

@once
    @push('scripts')
    <script>
    (() => {
      const bind = (root) => {
        if (!root || root.dataset.gmailBound === '1') return;
        root.dataset.gmailBound = '1';
        const messageEl = root.querySelector('[data-gmail-action-message]');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const setMessage = (text, isError = false) => {
          if (!messageEl) return;
          messageEl.textContent = text;
          messageEl.classList.toggle('text-danger', isError);
          messageEl.classList.toggle('text-success', !isError);
        };
        const postJson = async (url, body = {}) => {
          const response = await fetch(url, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrf || '',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
          });
          const payload = await response.json().catch(() => ({}));
          if (!response.ok || payload.ok === false) {
            throw new Error(payload.message || 'Request failed');
          }
          return payload;
        };
        root.querySelector('[data-gmail-sync-now]')?.addEventListener('click', async () => {
          setMessage('Running Gmail sync…');
          try {
            const result = await postJson(root.dataset.syncUrl || '');
            setMessage(result.message || 'Sync completed.');
          } catch (error) {
            setMessage(error.message || 'Sync failed.', true);
          }
        });
        root.querySelector('[data-gmail-rebaseline]')?.addEventListener('click', async () => {
          const mailbox = root.dataset.mailbox || '';
          const confirmed = window.confirm(
            `Re-baseline the Gmail cursor for ${mailbox || 'this mailbox'}?\n\nThis skips historical mail between the stuck cursor and now. Only use when the cursor is invalid or you accept losing that backlog.`
          );
          if (!confirmed) return;
          setMessage('Re-baselining cursor…');
          try {
            const result = await postJson(root.dataset.rebaselineUrl || '', { mailbox });
            setMessage(result.message || 'Re-baseline completed.');
          } catch (error) {
            setMessage(error.message || 'Re-baseline failed.', true);
          }
        });
      };
      document.querySelectorAll('[data-gmail-admin-actions]').forEach(bind);
    })();
    </script>
    @endpush
@endonce
