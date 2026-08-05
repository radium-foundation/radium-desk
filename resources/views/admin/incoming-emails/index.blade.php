@extends('layouts.app')

@section('title', 'Email Intake')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Email Intake</h1>
        <p class="text-muted mb-0">Processing queues for inbound email — not an inbox.</p>
    </div>

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
                            <th>Status</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($messages as $message)
                            <tr>
                                <td class="text-nowrap">{{ display_app_datetime($message->received_at) }}</td>
                                <td class="small">{{ $message->from_name ?: $message->from_email }}</td>
                                <td class="small">{{ $message->subject ?: '—' }}</td>
                                <td>{{ $message->status->label() }}</td>
                                <td class="small text-muted">
                                    {{ $message->ignore_reason ?: ($message->processing_error ?: '—') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-muted p-3">No messages in this queue.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
