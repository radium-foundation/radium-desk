@props([
    'failures' => [],
])

<section aria-labelledby="automation-failures-heading" class="mb-4">
    <h2 id="automation-failures-heading" class="h5 mb-3">Failures</h2>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($failures === [])
                <div class="p-4 text-center text-muted">No failed executions recorded.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Automation</th>
                                <th>Subject</th>
                                <th>Error</th>
                                <th>Retry Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($failures as $row)
                                <tr
                                    class="automation-health-row"
                                    role="button"
                                    tabindex="0"
                                    data-automation-health-detail-url="{{ $row['detail_url'] }}"
                                >
                                    <td class="text-nowrap small">{{ $row['timestamp_display'] }}</td>
                                    <td class="small">{{ $row['automation_label'] }}</td>
                                    <td class="small">{{ $row['subject'] }}</td>
                                    <td class="small text-danger">{{ $row['error_message'] }}</td>
                                    <td class="small text-muted">{{ $row['retry_status'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
