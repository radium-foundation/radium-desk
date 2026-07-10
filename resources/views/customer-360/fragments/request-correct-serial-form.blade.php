<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="request-correct-serial"
      class="workspace-note-dialog request-serial-dialog">
    @csrf
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif

    <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 mb-0">Request Correct Serial</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body workspace-note-dialog-body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        <section class="request-serial-dialog-section" aria-labelledby="request-correct-serial-customer-heading">
            <h3 class="request-serial-dialog-section-title" id="request-correct-serial-customer-heading">Customer</h3>
            <dl class="request-serial-dialog-dl">
                <div class="request-serial-dialog-dl-row">
                    <dt>Name</dt>
                    <dd>{{ filled($customerName ?? null) ? $customerName : 'Not Available' }}</dd>
                </div>
                <div class="request-serial-dialog-dl-row">
                    <dt>Phone</dt>
                    <dd>{{ filled($customerPhone ?? null) ? $customerPhone : 'Not Available' }}</dd>
                </div>
            </dl>
        </section>

        <section class="request-serial-dialog-section" aria-labelledby="request-correct-serial-serial-heading">
            <h3 class="request-serial-dialog-section-title" id="request-correct-serial-serial-heading">Serial Insight</h3>
            <dl class="request-serial-dialog-dl">
                <div class="request-serial-dialog-dl-row">
                    <dt>Current Serial</dt>
                    <dd>{{ filled($currentSerial ?? null) ? $currentSerial : 'Not Available' }}</dd>
                </div>
                <div class="request-serial-dialog-dl-row">
                    <dt>Status</dt>
                    <dd>{{ $serialInsightStatus ?? 'Unknown' }}</dd>
                </div>
                <div class="request-serial-dialog-dl-row">
                    <dt>Confidence</dt>
                    <dd>{{ $serialInsightConfidence ?? 'Unknown' }}</dd>
                </div>
                @if(filled($serialInsightExplanation ?? null))
                    <div class="request-serial-dialog-dl-row">
                        <dt>Explanation</dt>
                        <dd>{{ $serialInsightExplanation }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="request-serial-dialog-section" aria-labelledby="request-correct-serial-channels-heading">
            <h3 class="request-serial-dialog-section-title" id="request-correct-serial-channels-heading">Channels</h3>

            @php
                $whatsapp = $channelAvailability['whatsapp'] ?? ['available' => false];
                $email = $channelAvailability['email'] ?? ['available' => false];
                $communicationHistory = $communicationHistory ?? [];
                $whatsappCommunication = $communicationHistory['whatsapp'] ?? [];
                $emailCommunication = $communicationHistory['email'] ?? [];
            @endphp

            <ul class="request-serial-dialog-channel-list">
                <li class="request-serial-dialog-channel-item">
                    @if($whatsapp['available'] ?? false)
                        <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--available">
                            ✓ WhatsApp
                        </span>
                    @else
                        <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--unavailable">
                            ⚠ WhatsApp unavailable
                        </span>
                        @if(filled($whatsapp['reason'] ?? null))
                            <div class="request-serial-dialog-channel-reason">
                                <strong>Reason:</strong> {{ $whatsapp['reason'] }}
                            </div>
                        @endif
                    @endif

                    @php
                        $templateDiagnostics = $interaktTemplateDiagnostics ?? ($whatsapp['template_diagnostics'] ?? []);
                    @endphp
                    @if($templateDiagnostics !== [])
                        <div class="request-serial-dialog-channel-diagnostics" data-whatsapp-template-diagnostics>
                            <div class="request-serial-dialog-dl-row">
                                <dt>Interakt Template</dt>
                                <dd>
                                    @if($templateDiagnostics['template_missing'] ?? false)
                                        <span class="text-danger">❌ Template not configured</span>
                                    @else
                                        {{ $templateDiagnostics['template_name'] ?? 'Not configured' }}
                                    @endif
                                </dd>
                            </div>
                        </div>
                    @endif

                    @include('customer-360.partials.request-serial-channel-communication', [
                        'communication' => $whatsappCommunication,
                    ])
                </li>
                <li class="request-serial-dialog-channel-item">
                    @if($email['available'] ?? false)
                        <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--available">
                            ✓ Email
                        </span>
                    @else
                        <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--unavailable">
                            ⚠ Email unavailable
                        </span>
                        @if(filled($email['reason'] ?? null))
                            <div class="request-serial-dialog-channel-reason">
                                <strong>Reason:</strong> {{ $email['reason'] }}
                            </div>
                        @endif
                    @endif

                    @include('customer-360.partials.request-serial-channel-communication', [
                        'communication' => $emailCommunication,
                    ])
                </li>
            </ul>
        </section>

        <section class="request-serial-dialog-section" aria-labelledby="request-correct-serial-message-heading">
            <h3 class="request-serial-dialog-section-title" id="request-correct-serial-message-heading">Message</h3>
            <div class="request-serial-dialog-message">
                <p class="mb-2"><strong>Request:</strong></p>
                <ul class="request-serial-dialog-message-list mb-0">
                    <li>Confirm the correct device serial number</li>
                    <li class="request-serial-dialog-message-or">OR</li>
                    <li>Clear photo of device back label</li>
                </ul>
            </div>
        </section>
    </div>

    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit"
                class="btn btn-sm btn-primary px-4"
                @disabled(! ($canSendRequest ?? true))>
            Send Request
        </button>
    </div>
</form>
