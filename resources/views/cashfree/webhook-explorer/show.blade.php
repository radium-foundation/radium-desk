@extends('layouts.app')

@section('title', 'Webhook #'.$webhookLog->id)

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('cashfree.webhook-explorer.index') }}">Webhook Explorer</a></li>
                <li class="breadcrumb-item active" aria-current="page">Webhook #{{ $webhookLog->id }}</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Webhook Detail</h1>
        <p class="text-muted mb-0">Read-only view of the captured Cashfree webhook delivery.</p>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Delivery Information</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5 text-muted">ID</dt>
                        <dd class="col-sm-7 font-monospace">#{{ $webhookLog->id }}</dd>

                        <dt class="col-sm-5 text-muted">Received</dt>
                        <dd class="col-sm-7">{{ display_app_datetime_seconds($webhookLog->received_at) }}</dd>

                        <dt class="col-sm-5 text-muted">Stored</dt>
                        <dd class="col-sm-7">{{ display_app_datetime_seconds($webhookLog->created_at) }}</dd>

                        <dt class="col-sm-5 text-muted">Version</dt>
                        <dd class="col-sm-7">{{ $webhookLog->webhook_version ?: '—' }}</dd>

                        <dt class="col-sm-5 text-muted">Processing Status</dt>
                        <dd class="col-sm-7">@include('cashfree.webhook-explorer.partials.processing-status-badge', ['webhookLog' => $webhookLog])</dd>

                        <dt class="col-sm-5 text-muted">Source IP</dt>
                        <dd class="col-sm-7 font-monospace">{{ $webhookLog->source_ip ?: '—' }}</dd>

                        <dt class="col-sm-5 text-muted">User Agent</dt>
                        <dd class="col-sm-7"><small class="text-break">{{ $webhookLog->user_agent ?: '—' }}</small></dd>

                        <dt class="col-sm-5 text-muted">Processing Error</dt>
                        <dd class="col-sm-7">{{ $webhookLog->processing_error ?: '—' }}</dd>

                        <dt class="col-sm-5 text-muted">Processed At</dt>
                        <dd class="col-sm-7">
                            @if($webhookLog->processed_at)
                                {{ display_app_datetime_seconds($webhookLog->processed_at) }}
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-5 text-muted">Cashfree Payment ID</dt>
                        <dd class="col-sm-7 font-monospace">{{ $webhookLog->cf_payment_id ?: '—' }}</dd>

                        <dt class="col-sm-5 text-muted">Service Request</dt>
                        <dd class="col-sm-7">
                            @if($webhookLog->incident)
                                <a href="{{ route('incidents.show', $webhookLog->incident) }}">
                                    {{ $webhookLog->incident->display_reference }}
                                </a>
                            @else
                                —
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Parsed Payload</h2>
                </div>
                <div class="card-body">
                    @if(empty($webhookLog->request_payload))
                        <p class="text-muted mb-0">No parsed payload recorded.</p>
                    @else
                        <pre class="bg-light border rounded p-3 mb-0 small overflow-auto"><code>{{ json_encode($webhookLog->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Request Headers</h2>
                </div>
                <div class="card-body">
                    @if(empty($webhookLog->request_headers))
                        <p class="text-muted mb-0">No headers recorded.</p>
                    @else
                        <pre class="bg-light border rounded p-3 mb-0 small overflow-auto"><code>{{ json_encode($webhookLog->request_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Raw Body</h2>
                </div>
                <div class="card-body">
                    @if($webhookLog->raw_body === null || $webhookLog->raw_body === '')
                        <p class="text-muted mb-0">No raw body recorded.</p>
                    @else
                        <pre class="bg-light border rounded p-3 mb-0 small overflow-auto"><code>{{ $webhookLog->raw_body }}</code></pre>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
