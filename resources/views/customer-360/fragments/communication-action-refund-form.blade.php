<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="communication-action"
      class="workspace-note-dialog request-serial-dialog communication-action-dialog">
    @csrf
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif

    <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 mb-0">{{ $communicationAction->name }}</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body workspace-note-dialog-body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        <p class="small text-muted mb-3">{{ $communicationAction->description }}</p>

        <section class="request-serial-dialog-section" aria-labelledby="communication-action-customer-heading">
            <h3 class="request-serial-dialog-section-title" id="communication-action-customer-heading">Customer</h3>
            <dl class="request-serial-dialog-dl">
                <div class="request-serial-dialog-dl-row">
                    <dt>Name</dt>
                    <dd>{{ filled($customerName ?? null) ? $customerName : 'Not Available' }}</dd>
                </div>
                <div class="request-serial-dialog-dl-row">
                    <dt>Phone</dt>
                    <dd>{{ filled($customerPhone ?? null) ? $customerPhone : 'Not Available' }}</dd>
                </div>
                @if(in_array('email', collect($communicationAction->channels)->map(fn ($channel) => $channel->value)->all(), true))
                    <div class="request-serial-dialog-dl-row">
                        <dt>Email</dt>
                        <dd>{{ filled($customerEmail ?? null) ? $customerEmail : 'Not Available' }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="request-serial-dialog-section" aria-labelledby="communication-action-channels-heading">
            <h3 class="request-serial-dialog-section-title" id="communication-action-channels-heading">Channels</h3>

            <ul class="request-serial-dialog-channel-list">
                @foreach($communicationAction->channels as $channel)
                    @php
                        $availability = $channelAvailability[$channel->value] ?? ['available' => false];
                    @endphp
                    <li class="request-serial-dialog-channel-item">
                        @if($availability['available'] ?? false)
                            <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--available">
                                ✓ {{ $channel->label() }}
                            </span>
                        @else
                            <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--unavailable">
                                ⚠ {{ $channel->label() }} unavailable
                            </span>
                            @if(filled($availability['reason'] ?? null))
                                <div class="request-serial-dialog-channel-reason">
                                    <strong>Reason:</strong> {{ $availability['reason'] }}
                                </div>
                            @endif
                        @endif

                        @if($channel === \App\Enums\NotificationChannelType::WhatsApp && ($interaktTemplateDiagnostics ?? []) !== [])
                            <div class="request-serial-dialog-channel-diagnostics" data-whatsapp-template-diagnostics>
                                <div class="request-serial-dialog-dl-row">
                                    <dt>Interakt Template</dt>
                                    <dd>
                                        @if($interaktTemplateDiagnostics['template_missing'] ?? false)
                                            <span class="text-danger">Template not configured</span>
                                        @else
                                            {{ $interaktTemplateDiagnostics['template_name'] ?? 'Not configured' }}
                                        @endif
                                    </dd>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        @if($communicationAction->variables !== [])
            <section class="request-serial-dialog-section" aria-labelledby="communication-action-variables-heading">
                <h3 class="request-serial-dialog-section-title" id="communication-action-variables-heading">Details</h3>

                @foreach($communicationAction->variables as $variable)
                    <div class="mb-3">
                        <label class="form-label small mb-1" for="communication-action-{{ $variable->key }}">
                            {{ $variable->label }}
                            @if($variable->required)
                                <span class="text-danger">*</span>
                            @endif
                        </label>
                        <input type="text"
                               class="form-control form-control-sm"
                               id="communication-action-{{ $variable->key }}"
                               name="{{ $variable->key }}"
                               value="{{ old($variable->key) }}"
                               @if($variable->required) required @endif>
                    </div>
                @endforeach
            </section>
        @endif

        <section class="request-serial-dialog-section" aria-labelledby="communication-action-confirm-heading">
            <h3 class="request-serial-dialog-section-title" id="communication-action-confirm-heading">Confirmation</h3>
            <p class="small mb-0">
                Review the customer details and supported channels above. Send when ready, or close this dialog without sending.
            </p>
        </section>
    </div>

    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close without sending</button>
        <button type="submit"
                class="btn btn-sm btn-primary px-4"
                @disabled(! ($canSendAction ?? true))>
            Send
        </button>
    </div>
</form>
