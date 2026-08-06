@extends('layouts.app')

@section('title', $isLearningCenter ? 'IRA Learning Center' : 'Email Intake')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">{{ $isLearningCenter ? 'IRA Learning Center' : 'Email Intake' }}</h1>
        <p class="text-muted mb-0">
            @if($isLearningCenter)
                Review-and-teach workspace for inbound email — not a Gmail inbox.
            @else
                Processing queues for inbound email — not an inbox.
            @endif
        </p>
    </div>

    @if(session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger py-2">
            <ul class="mb-0 small">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach($queues as $queueOption)
            @php
                $count = $counts[$queueOption->value] ?? 0;
            @endphp
            @if($count > 0 || $queueOption === $queue)
                <a href="{{ route('admin.incoming-emails.index', ['queue' => $queueOption->value]) }}"
                   @class([
                       'btn btn-sm',
                       $queueOption === $queue ? 'btn-primary' : 'btn-outline-secondary',
                   ])
                   title="{{ $queueOption->tooltip() }}">
                    {{ $queueOption->emoji() }} {{ $queueOption->label() }}
                    @if($count > 0)
                        <span class="ms-1">({{ number_format($count) }})</span>
                    @endif
                </a>
            @endif
        @endforeach
    </div>

    <div class="mb-3">
        <a href="{{ route('admin.gmail.logs') }}" class="btn btn-sm btn-outline-secondary">Gmail Sync Logs</a>
        <a href="{{ route('admin.gmail.failed-messages') }}" class="btn btn-sm btn-outline-secondary">Gmail Failed Messages</a>
        <a href="{{ route('admin.platform.index') }}" class="btn btn-sm btn-outline-secondary">Back to Platform</a>
    </div>

    @if($isLearningCenter)
        @include('admin.incoming-emails.partials.learning-center')
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">{{ $queue->label() }}</h2>
                <p class="text-muted small mb-0">{{ $queue->tooltip() }}</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Received</th>
                                <th>From</th>
                                <th>Subject</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($messages as $message)
                                <tr>
                                    <td class="text-nowrap">{{ display_app_datetime($message->received_at) }}</td>
                                    <td class="small">{{ $message->from_name ?: $message->from_email }}</td>
                                    <td class="small">{{ $message->subject ?: '—' }}</td>
                                    <td class="small text-muted">
                                        {{ $message->ignore_reason ?: ($message->processing_error ?: '—') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-muted p-3">No messages in this queue.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection

@if($isLearningCenter)
    @push('scripts')
        <script>
            (function () {
                function selectedIds(root) {
                    return Array.from(root.querySelectorAll('[data-ira-card-select]:checked')).map((input) => input.value);
                }

                function syncBulkSelection(root) {
                    const ids = selectedIds(root);
                    const holder = root.querySelector('[data-ira-selected-inputs]');
                    const applyButton = root.querySelector('[data-ira-bulk-apply]');

                    if (holder) {
                        holder.innerHTML = ids
                            .map((id) => `<input type="hidden" name="message_ids[]" value="${id}">`)
                            .join('');
                    }

                    if (applyButton) {
                        applyButton.disabled = ids.length === 0;
                    }
                }

                function syncBulkPanels(root) {
                    const action = root.querySelector('[data-ira-bulk-action]')?.value || '';

                    root.querySelectorAll('[data-ira-panel]').forEach((panel) => {
                        const match = panel.getAttribute('data-ira-panel') === action;
                        panel.hidden = !match;
                        panel.querySelectorAll('select, input').forEach((field) => {
                            field.disabled = !match;
                        });
                    });
                }

                function bootLearningCenter(root) {
                    const selectAll = root.querySelector('[data-ira-select-all]');
                    const cardChecks = () => Array.from(root.querySelectorAll('[data-ira-card-select]'));

                    selectAll?.addEventListener('change', () => {
                        cardChecks().forEach((input) => {
                            input.checked = selectAll.checked;
                        });
                        syncBulkSelection(root);
                    });

                    root.addEventListener('change', (event) => {
                        if (event.target?.matches?.('[data-ira-card-select]')) {
                            if (selectAll) {
                                const checks = cardChecks();
                                selectAll.checked = checks.length > 0 && checks.every((input) => input.checked);
                            }
                            syncBulkSelection(root);
                        }

                        if (event.target?.matches?.('[data-ira-bulk-action]')) {
                            syncBulkPanels(root);
                        }
                    });

                    root.querySelector('[data-ira-bulk-form]')?.addEventListener('submit', (event) => {
                        if (selectedIds(root).length === 0) {
                            event.preventDefault();
                            return;
                        }
                        syncBulkSelection(root);
                    });

                    syncBulkPanels(root);
                    syncBulkSelection(root);
                }

                document.querySelectorAll('[data-ira-learning-center]').forEach((root) => {
                    bootLearningCenter(root);
                });
            })();
        </script>
    @endpush
@endif
