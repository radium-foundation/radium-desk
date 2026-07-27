@props([
    'actionCenter',
    'incident',
])

@php
    $primaryLabel = (string) ($actionCenter['primary_label'] ?? 'Primary action');
    $primaryText = (string) ($actionCenter['primary_text'] ?? '');
    $why = (string) ($actionCenter['why'] ?? '');
    $quick = is_array($actionCenter['quick_actions'] ?? null) ? $actionCenter['quick_actions'] : [];
    $whatsapp = $quick['whatsapp'] ?? null;
    $email = $quick['email'] ?? null;
    $internalNote = $quick['internal_note'] ?? ($actionCenter['internal_note'] ?? null);
    $suggestedReply = $actionCenter['suggested_reply'] ?? $whatsapp;
    $checklist = is_array($actionCenter['checklist'] ?? null) ? $actionCenter['checklist'] : [];
    $auditUrl = $actionCenter['audit_url'] ?? null;
    $hasSerialAction = (bool) ($actionCenter['has_serial_action'] ?? false);
    $serialPending = (bool) ($actionCenter['serial_request_pending'] ?? false);
@endphp

<section {{ $attributes->merge(['class' => 'c360-action-center']) }}
         data-c360-action-center
         data-ai-workbench-root
         data-ai-workbench-incident-id="{{ $incident->id }}"
         @if($auditUrl) data-ai-workbench-audit-url="{{ $auditUrl }}" @endif
         aria-labelledby="c360-action-center-heading">

    <header class="c360-action-center-header">
        <h3 class="c360-ira-panel-section-title" id="c360-action-center-heading">Action Center</h3>
    </header>

    <div class="c360-action-center-primary">
        <p class="c360-action-center-primary-label">{{ $primaryLabel }}</p>
        @if($primaryText !== '')
            <p class="c360-action-center-primary-text" data-ira-summary-block="recommendation">
                {{ $primaryText }}
            </p>
        @endif

        @if($hasSerialAction)
            <button type="button"
                    class="c360-ira-panel-primary-btn"
                    data-workspace-trigger="request-correct-serial"
                    data-workspace-incident-id="{{ $incident->id }}"
                    data-workspace-context="customer">
                <i class="bi bi-send" aria-hidden="true"></i>
                {{ $actionCenter['serial_action_label'] ?? 'Send request' }}
            </button>
        @elseif($serialPending)
            <div class="c360-ira-panel-primary-btn c360-ira-panel-primary-btn--sent" role="status">
                <i class="bi bi-check-circle" aria-hidden="true"></i>
                <span>Serial Requested</span>
            </div>
        @endif
    </div>

    @if($why !== '' && $why !== $primaryText)
        <p class="c360-action-center-why">
            <span class="c360-action-center-why-label">Why this action</span>
            {{ $why }}
        </p>
    @endif

    @if(filled($suggestedReply))
        <div class="c360-action-center-reply">
            <p class="c360-action-center-reply-label">Suggested reply</p>
            <p class="c360-action-center-reply-text">{{ $suggestedReply }}</p>
        </div>
    @endif

    <div class="c360-action-center-quick" role="group" aria-label="Quick actions">
        @if(filled($whatsapp))
            <button type="button"
                    class="c360-action-center-copy-btn"
                    data-ai-workbench-copy
                    data-artifact-key="reply_whatsapp"
                    data-copy-value="{{ $whatsapp }}">
                <i class="bi bi-whatsapp" aria-hidden="true"></i>
                Copy WhatsApp
            </button>
        @endif
        @if(filled($email))
            <button type="button"
                    class="c360-action-center-copy-btn"
                    data-ai-workbench-copy
                    data-artifact-key="reply_email"
                    data-copy-value="{{ $email }}">
                <i class="bi bi-envelope" aria-hidden="true"></i>
                Copy Email
            </button>
        @endif
        @if(filled($internalNote))
            <button type="button"
                    class="c360-action-center-copy-btn"
                    data-ai-workbench-copy
                    data-artifact-key="reply_internal_note"
                    data-copy-value="{{ $internalNote }}">
                <i class="bi bi-journal-text" aria-hidden="true"></i>
                Copy Internal Note
            </button>
        @endif
    </div>

    @if($checklist !== [])
        <ul class="c360-action-center-checklist" aria-label="Case checklist">
            @foreach($checklist as $item)
                @php $done = (bool) ($item['done'] ?? false); @endphp
                <li @class(['c360-action-center-check', 'c360-action-center-check--done' => $done])>
                    <span class="c360-action-center-check-mark" aria-hidden="true">
                        {{ $done ? '☑' : '☐' }}
                    </span>
                    <span class="c360-action-center-check-label">{{ $item['label'] ?? '' }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
