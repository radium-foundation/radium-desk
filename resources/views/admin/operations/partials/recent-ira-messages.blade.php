@props([
    'messages' => [],
])

<section aria-labelledby="recent-ira-messages-heading">
    <h2 id="recent-ira-messages-heading" class="h5 mb-3">Recent Ira Messages</h2>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($messages === [])
                <div class="p-4 text-center text-muted">No Ira messages recorded yet.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Recipient</th>
                                <th>Type</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($messages as $message)
                                <tr>
                                    <td class="text-nowrap">{{ display_app_datetime_seconds($message['timestamp']) }}</td>
                                    <td>{{ $message['recipient'] }}</td>
                                    <td>{{ $message['type'] }}</td>
                                    <td>{{ $message['title'] }}</td>
                                    <td>
                                        @php
                                            $statusClass = match ($message['status']) {
                                                'sent' => 'text-success',
                                                'failed' => 'text-danger',
                                                default => 'text-muted',
                                            };
                                        @endphp
                                        <span class="{{ $statusClass }}">{{ $message['status_label'] }}</span>
                                    </td>
                                    <td class="small text-muted">
                                        @if($message['status'] === 'failed')
                                            {{ $message['error_message'] ?? 'Delivery failed.' }}
                                        @elseif($message['status'] === 'pending')
                                            Awaiting delivery
                                        @else
                                            {{ $message['sent_at'] ? 'Sent '.display_app_datetime_seconds($message['sent_at']) : '—' }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
