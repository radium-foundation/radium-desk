@php
    $whatsapp = $channelAvailability['whatsapp'] ?? ['available' => false];
    $email = $channelAvailability['email'] ?? ['available' => false];
    $communicationHistory = $communicationHistory ?? [];
    $whatsappCommunication = $communicationHistory['whatsapp'] ?? [];
    $emailCommunication = $communicationHistory['email'] ?? [];
    $templateDiagnostics = $interaktTemplateDiagnostics ?? ($whatsapp['template_diagnostics'] ?? []);

    $serialStatusLabel = $serialInsightStatus ?? 'Unknown';
    $serialStatusTone = match (strtolower($serialStatusLabel)) {
        'valid' => 'valid',
        'suspicious', 'needs verification' => 'warning',
        'missing' => 'invalid',
        default => 'neutral',
    };
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="request-correct-serial"
      class="workspace-note-dialog request-serial-dialog request-correct-serial-dialog">
    @csrf
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif

    <div class="modal-header border-0 pb-0 request-correct-serial-dialog__header">
        <h2 class="modal-title h5 mb-0">Request Correct Serial</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body workspace-note-dialog-body request-correct-serial-dialog__body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        <div class="request-correct-serial-dialog__overview">
            <section class="request-correct-serial-dialog__card"
                     aria-labelledby="request-correct-serial-customer-heading">
                <h3 class="request-correct-serial-dialog__section-title"
                    id="request-correct-serial-customer-heading">
                    <i class="bi bi-person" aria-hidden="true"></i>
                    Customer details
                </h3>
                <dl class="request-correct-serial-dialog__detail-list">
                    <div class="request-correct-serial-dialog__detail-row">
                        <dt>
                            <i class="bi bi-person" aria-hidden="true"></i>
                            Name
                        </dt>
                        <dd>{{ filled($customerName ?? null) ? $customerName : 'Not Available' }}</dd>
                    </div>
                    <div class="request-correct-serial-dialog__detail-row">
                        <dt>
                            <i class="bi bi-telephone" aria-hidden="true"></i>
                            Phone
                        </dt>
                        <dd>{{ filled($customerPhone ?? null) ? $customerPhone : 'Not Available' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="request-correct-serial-dialog__card"
                     aria-labelledby="request-correct-serial-serial-heading">
                <h3 class="request-correct-serial-dialog__section-title"
                    id="request-correct-serial-serial-heading">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    Serial insight
                </h3>
                <dl class="request-correct-serial-dialog__detail-list">
                    <div class="request-correct-serial-dialog__detail-row">
                        <dt>Current serial</dt>
                        <dd>{{ filled($currentSerial ?? null) ? $currentSerial : 'Not Available' }}</dd>
                    </div>
                    <div class="request-correct-serial-dialog__detail-row">
                        <dt>Status</dt>
                        <dd>
                            <span @class([
                                'request-correct-serial-dialog__status-badge',
                                'request-correct-serial-dialog__status-badge--valid' => $serialStatusTone === 'valid',
                                'request-correct-serial-dialog__status-badge--warning' => $serialStatusTone === 'warning',
                                'request-correct-serial-dialog__status-badge--invalid' => $serialStatusTone === 'invalid',
                                'request-correct-serial-dialog__status-badge--neutral' => $serialStatusTone === 'neutral',
                            ])>
                                {{ $serialStatusLabel }}
                            </span>
                        </dd>
                    </div>
                    <div class="request-correct-serial-dialog__detail-row">
                        <dt>Confidence</dt>
                        <dd>{{ $serialInsightConfidence ?? 'Unknown' }}</dd>
                    </div>
                </dl>
                @if(filled($serialInsightExplanation ?? null))
                    <p class="request-correct-serial-dialog__insight-note mb-0">
                        {{ $serialInsightExplanation }}
                    </p>
                @endif
            </section>
        </div>

        <section class="request-correct-serial-dialog__card request-correct-serial-dialog__card--channels"
                 aria-labelledby="request-correct-serial-channels-heading">
            <h3 class="request-correct-serial-dialog__section-title"
                id="request-correct-serial-channels-heading">
                <i class="bi bi-chat-dots" aria-hidden="true"></i>
                Target channels
            </h3>

            <div class="request-correct-serial-dialog__channel-grid">
                <div @class([
                        'request-correct-serial-dialog__channel-chip',
                        'is-selected' => $whatsapp['available'] ?? false,
                        'is-unavailable' => ! ($whatsapp['available'] ?? false),
                    ])>
                    <div class="request-correct-serial-dialog__channel-chip-header">
                        <span class="request-correct-serial-dialog__channel-chip-label">
                            @if($whatsapp['available'] ?? false)
                                <i class="bi bi-check2 request-correct-serial-dialog__channel-check" aria-hidden="true"></i>
                            @else
                                <i class="bi bi-exclamation-circle request-correct-serial-dialog__channel-warning" aria-hidden="true"></i>
                            @endif
                            WhatsApp
                        </span>
                    </div>

                    @if(! ($whatsapp['available'] ?? false) && filled($whatsapp['reason'] ?? null))
                        <p class="request-correct-serial-dialog__channel-note mb-0">
                            {{ $whatsapp['reason'] }}
                        </p>
                    @endif

                    @if($templateDiagnostics !== [])
                        <div class="request-correct-serial-dialog__channel-meta"
                             data-whatsapp-template-diagnostics>
                            <span class="request-correct-serial-dialog__channel-meta-label">Interakt template</span>
                            <span class="request-correct-serial-dialog__channel-meta-value">
                                @if($templateDiagnostics['template_missing'] ?? false)
                                    <span class="text-danger">Template not configured</span>
                                @else
                                    {{ $templateDiagnostics['template_name'] ?? 'Not configured' }}
                                @endif
                            </span>
                        </div>
                    @endif

                    @include('customer-360.partials.request-serial-channel-communication', [
                        'communication' => $whatsappCommunication,
                        'hidePendingStatus' => true,
                    ])
                </div>

                <div @class([
                        'request-correct-serial-dialog__channel-chip',
                        'is-selected' => $email['available'] ?? false,
                        'is-unavailable' => ! ($email['available'] ?? false),
                    ])>
                    <div class="request-correct-serial-dialog__channel-chip-header">
                        <span class="request-correct-serial-dialog__channel-chip-label">
                            @if($email['available'] ?? false)
                                <i class="bi bi-check2 request-correct-serial-dialog__channel-check" aria-hidden="true"></i>
                            @else
                                <i class="bi bi-exclamation-circle request-correct-serial-dialog__channel-warning" aria-hidden="true"></i>
                            @endif
                            Email
                        </span>
                    </div>

                    @if(! ($email['available'] ?? false) && filled($email['reason'] ?? null))
                        <p class="request-correct-serial-dialog__channel-note mb-0">
                            {{ $email['reason'] }}
                        </p>
                    @endif

                    @include('customer-360.partials.request-serial-channel-communication', [
                        'communication' => $emailCommunication,
                        'hidePendingStatus' => true,
                    ])
                </div>
            </div>
        </section>

        <section class="request-correct-serial-dialog__card"
                 aria-labelledby="request-correct-serial-message-heading">
            <h3 class="request-correct-serial-dialog__section-title"
                id="request-correct-serial-message-heading">
                <i class="bi bi-file-text" aria-hidden="true"></i>
                Message preview
            </h3>
            <div class="request-correct-serial-dialog__message-preview">
                <p class="request-correct-serial-dialog__message-lead mb-2">Request:</p>
                <ul class="request-correct-serial-dialog__message-list mb-0">
                    <li>Confirm the correct device serial number</li>
                    <li class="request-correct-serial-dialog__message-or">OR</li>
                    <li>Clear photo of device back label</li>
                </ul>
            </div>
        </section>
    </div>

    <div class="modal-footer border-0 pt-0 request-correct-serial-dialog__footer">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit"
                class="btn btn-sm btn-primary px-4"
                @disabled(! ($canSendRequest ?? true))>
            Send Request
        </button>
    </div>
</form>
