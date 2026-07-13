@php
    $centerConfig = $communicationCenterConfig ?? [];
    $selectedActionKey = $centerConfig['selectedActionKey'] ?? null;
    $selectedTarget = $centerConfig['selectedTarget'] ?? null;
    $targetGroupLabel = $centerConfig['targetGroupLabels'][$selectedActionKey] ?? 'Target';
    $targets = $centerConfig['targetsByAction'][$selectedActionKey] ?? [];
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="communication-action"
      data-communication-center-form
      class="workspace-note-dialog request-serial-dialog communication-action-dialog">
    @csrf
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif

    <script type="application/json" data-communication-center-config>
        @json($centerConfig)
    </script>

    <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 mb-0">Communication</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body workspace-note-dialog-body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        <div class="mb-3">
            <label class="form-label small mb-1" for="communication-center-action">Communication</label>
            <select class="form-select form-select-sm"
                    id="communication-center-action"
                    name="communication_action_key"
                    data-communication-center-action>
                @foreach($centerConfig['actions'] ?? [] as $action)
                    <option value="{{ $action['key'] }}"
                            @selected($action['key'] === $selectedActionKey)>
                        {{ $action['name'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label small mb-1"
                   for="communication-center-target"
                   data-communication-center-target-label>
                {{ $targetGroupLabel }}
            </label>
            <select class="form-select form-select-sm"
                    id="communication-center-target"
                    name="communication_target"
                    data-communication-center-target
                    @if($targets === []) disabled @endif>
                @foreach($targets as $target)
                    <option value="{{ $target['value'] }}"
                            @selected((string) $target['value'] === (string) $selectedTarget)>
                        {{ $target['label'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label small mb-1" for="communication-center-delivery-channel">Send Via</label>
            <select class="form-select form-select-sm"
                    id="communication-center-delivery-channel"
                    name="delivery_channel"
                    data-communication-center-delivery-channel>
                <option value="both" selected>Both</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="email">Email</option>
            </select>
        </div>

        <section class="request-serial-dialog-section mb-0"
                 aria-labelledby="communication-center-channels-heading">
            <ul class="request-serial-dialog-channel-list mb-0"
                data-communication-center-channel-list>
                @foreach($centerConfig['selectedChannelAvailability'] ?? [] as $channel)
                    <li class="request-serial-dialog-channel-item">
                        @if($channel['available'] ?? false)
                            <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--available">
                                ✓ {{ $channel['label'] }} available
                            </span>
                        @else
                            <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--unavailable">
                                ⚠ {{ $channel['label'] }} unavailable
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit"
                class="btn btn-sm btn-primary px-4"
                data-workspace-submit-label="Sending…"
                data-communication-center-submit
                @disabled(! ($canSendAction ?? true))>
            Send
        </button>
    </div>
</form>
