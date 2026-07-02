<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="request-serial"
      class="workspace-note-dialog request-serial-dialog">
    @csrf
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif

    <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 mb-0">Request Serial Number</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body workspace-note-dialog-body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        <section class="request-serial-dialog-section" aria-labelledby="request-serial-customer-heading">
            <h3 class="request-serial-dialog-section-title" id="request-serial-customer-heading">Customer</h3>
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

        <section class="request-serial-dialog-section" aria-labelledby="request-serial-channels-heading">
            <h3 class="request-serial-dialog-section-title" id="request-serial-channels-heading">Channels</h3>

            @php
                $whatsapp = $channelAvailability['whatsapp'] ?? ['available' => false];
                $email = $channelAvailability['email'] ?? ['available' => false];
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
                        @if(filled($whatsapp['fallback_note'] ?? null))
                            <div class="request-serial-dialog-channel-note">{{ $whatsapp['fallback_note'] }}</div>
                        @endif
                    @endif
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
                        @if(filled($email['fallback_note'] ?? null))
                            <div class="request-serial-dialog-channel-note">{{ $email['fallback_note'] }}</div>
                        @endif
                    @endif
                </li>
            </ul>
        </section>

        <section class="request-serial-dialog-section" aria-labelledby="request-serial-message-heading">
            <h3 class="request-serial-dialog-section-title" id="request-serial-message-heading">Message</h3>
            <div class="request-serial-dialog-message">
                <p class="mb-2"><strong>Request:</strong></p>
                <ul class="request-serial-dialog-message-list mb-0">
                    <li>Serial Number</li>
                    <li class="request-serial-dialog-message-or">OR</li>
                    <li>Clear photo of device back label</li>
                </ul>
            </div>
        </section>

        <section class="request-serial-dialog-section" aria-labelledby="request-serial-waiting-heading">
            <h3 class="request-serial-dialog-section-title" id="request-serial-waiting-heading">Waiting State</h3>
            <p class="request-serial-dialog-waiting-state mb-0">
                @if($hasActiveSerialWaitingState ?? false)
                    Customer Serial Pending
                @else
                    Customer Serial Pending will start after a successful send.
                @endif
            </p>
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
