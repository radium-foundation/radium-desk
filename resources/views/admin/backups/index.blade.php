@extends('layouts.app')

@section('title', 'Backup Status')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Backup Status</h1>
        <p class="text-muted mb-0">
            Read-only view of encrypted backup metadata. Super Admin only.
        </p>
    </div>

    @include('navigation.administration-workspace-nav', ['active' => 'backups'])

    <div class="alert alert-info border-0 shadow-sm" role="status">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        {{ $status['read_only_notice'] }}
    </div>

    @if(! $status['staging_accessible'])
        <div class="alert alert-warning border-0 shadow-sm" role="alert">
            {{ $status['staging_unavailable_message'] }}
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">Schedule</h2>
            <p class="mb-0 text-muted">
                Automated backups run twice daily at <strong>{{ $status['schedule_label'] }}</strong>.
            </p>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">Latest successful backup</h2>

            @if($status['latest'])
                @php($latest = $status['latest'])
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Backup ID</dt>
                    <dd class="col-sm-8"><code>{{ $latest['backup_id'] }}</code></dd>

                    <dt class="col-sm-4 text-muted">Created</dt>
                    <dd class="col-sm-8">{{ $latest['created_at'] ?? '—' }}</dd>

                    <dt class="col-sm-4 text-muted">Application</dt>
                    <dd class="col-sm-8">
                        @if($latest['application_version'])
                            v{{ $latest['application_version'] }}
                            @if($latest['application_build'])
                                <span class="text-muted">({{ $latest['application_build'] }})</span>
                            @endif
                        @else
                            —
                        @endif
                    </dd>

                    <dt class="col-sm-4 text-muted">Database backup</dt>
                    <dd class="col-sm-8">{{ $latest['database_size_label'] }}</dd>

                    <dt class="col-sm-4 text-muted">Secrets backup</dt>
                    <dd class="col-sm-8">{{ $latest['secrets_size_label'] }}</dd>

                    <dt class="col-sm-4 text-muted">Cloud upload</dt>
                    <dd class="col-sm-8">{{ $latest['cloud_upload_status_label'] }}</dd>

                    <dt class="col-sm-4 text-muted">Integrity</dt>
                    <dd class="col-sm-8">{{ $latest['integrity_status_label'] }}</dd>
                </dl>
            @else
                <p class="text-muted mb-0">No successful backup metadata is available to display.</p>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">Cloud backup inventory</h2>
            <p class="text-muted">{{ $cloudInventory['read_only_notice'] }}</p>

            @if($cloudInventory['index_parse_error'])
                <div class="alert alert-warning border-0 mb-0" role="alert">
                    {{ $cloudInventory['index_parse_error_message'] }}
                </div>
            @elseif(! $cloudInventory['index_accessible'])
                <div class="alert alert-warning border-0 mb-0" role="alert">
                    {{ $cloudInventory['index_unavailable_message'] }}
                </div>
            @elseif($cloudInventory['entries'] === [])
                <p class="text-muted mb-0">No completed Cloud backups are listed in the sanitized index.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Backup ID</th>
                                <th scope="col">Date/Time (IST)</th>
                                <th scope="col">Total size</th>
                                <th scope="col">Manifest</th>
                                <th scope="col">Upload complete</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cloudInventory['entries'] as $entry)
                                <tr>
                                    <td><code>{{ $entry['backup_id'] }}</code></td>
                                    <td>{{ $entry['timestamp_label'] }}</td>
                                    <td>{{ $entry['total_size_label'] }}</td>
                                    <td>{{ $entry['manifest_present_label'] }}</td>
                                    <td>{{ $entry['upload_complete_label'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">How to restore</h2>
            <p class="mb-2">
                Restore is <strong>not available from Desk</strong>. Recovery is a manual operator procedure only.
            </p>
            <ul class="mb-3">
                <li>Identify the backup ID you need from the tables above.</li>
                <li>Work with an operator who can access the encrypted backup bundles on the server or Cloud storage.</li>
                <li>Decrypt and restore on a non-production environment first, then verify the application version and data before any production cutover.</li>
                <li>Never restore secrets or database data onto a running production app without an approved maintenance window and rollback plan.</li>
            </ul>
            <p class="text-muted mb-0">
                Full manual-restore safety steps are in the Backup Runbook
                (<code>{{ config('backup.restore_runbook_path') }}#{{ config('backup.restore_runbook_anchor') }}</code>).
            </p>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-3">Recent backup history (local staging)</h2>

            @if($status['history'] === [])
                <p class="text-muted mb-0">No backup runs found.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Backup ID</th>
                                <th scope="col">Created</th>
                                <th scope="col">Version</th>
                                <th scope="col">DB size</th>
                                <th scope="col">Secrets</th>
                                <th scope="col">Cloud</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($status['history'] as $run)
                                <tr>
                                    <td><code>{{ $run['backup_id'] }}</code></td>
                                    <td>{{ $run['created_at'] ?? '—' }}</td>
                                    <td>
                                        @if($run['application_version'])
                                            {{ $run['application_version'] }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $run['database_size_label'] }}</td>
                                    <td>{{ $run['secrets_size_label'] }}</td>
                                    <td>{{ $run['cloud_upload_status_label'] }}</td>
                                    <td>{{ $run['status_label'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
