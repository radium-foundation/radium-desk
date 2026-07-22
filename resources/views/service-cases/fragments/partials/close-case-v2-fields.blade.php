@php
    use App\Enums\ServiceCaseCloseNotificationPreference;
    use App\Enums\ServiceCaseCloseReasonForClosing;
    use App\Enums\ServiceCaseCloseResolutionType;
    use App\Enums\WorkspaceActionType;

    $reasonValue = old('reason_for_closing', $formPayload['reason_for_closing'] ?? '');
    $resolutionTypeValue = old('resolution_type', $formPayload['resolution_type'] ?? '');
    $notificationPreferenceValue = old(
        'notification_preference',
        $formPayload['notification_preference'] ?? ServiceCaseCloseNotificationPreference::No->value,
    );
    $expectedFromValue = old('expected_from', $formPayload['expected_from'] ?? '');
    $expectedDateValue = old('expected_date', $formPayload['expected_date'] ?? '');
    $cnrCommunicationPreferenceValue = old(
        'cnr_communication_preference',
        $formPayload['cnr_communication_preference'] ?? ServiceCaseCloseNotificationPreference::WhatsApp->value,
    );
    $existingCaseIdValue = old('existing_case_id', $formPayload['existing_case_id'] ?? '');
    $replacementOrderIdValue = old('replacement_order_id', $formPayload['replacement_order_id'] ?? '');
    $approvalReferenceValue = old('approval_reference', $formPayload['approval_reference'] ?? '');

    $selectedReason = ServiceCaseCloseReasonForClosing::tryFrom((string) $reasonValue);
    $showsNotification = $selectedReason?->showsCustomerNotification() ?? true;
@endphp

<div class="workspace-close-v2">
    <div class="mb-2">
        <label for="workspace_close_reason" class="form-label workspace-action-field-label">
            Reason for Closing <span class="text-danger">*</span>
        </label>
        <select name="reason_for_closing"
                id="workspace_close_reason"
                class="form-select form-select-sm @error('reason_for_closing') is-invalid @enderror"
                data-workspace-close-reason
                required
                @disabled($selectedAction !== WorkspaceActionType::Close)>
            <option value="" disabled @selected($reasonValue === '')>Select reason</option>
            @foreach($closeReasonsForClosing as $reason)
                <option value="{{ $reason->value }}"
                        data-shows-notification="{{ $reason->showsCustomerNotification() ? '1' : '0' }}"
                        @selected($reasonValue === $reason->value)>
                    {{ $reason->label() }}
                </option>
            @endforeach
        </select>
        @error('reason_for_closing')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-2 @if($reasonValue !== ServiceCaseCloseReasonForClosing::IssueResolved->value) d-none @endif"
         data-workspace-close-field-group="resolution_type">
        <label for="workspace_close_resolution_type" class="form-label workspace-action-field-label">Resolution Type</label>
        <select name="resolution_type"
                id="workspace_close_resolution_type"
                class="form-select form-select-sm @error('resolution_type') is-invalid @enderror"
                @disabled($selectedAction !== WorkspaceActionType::Close)>
            <option value="" @selected($resolutionTypeValue === '')>Select resolution type (optional)</option>
            @foreach($closeResolutionTypes as $resolutionType)
                <option value="{{ $resolutionType->value }}" @selected($resolutionTypeValue === $resolutionType->value)>
                    {{ $resolutionType->label() }}
                </option>
            @endforeach
        </select>
        @error('resolution_type')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="workspace-close-dynamic-fields">
        <div class="mb-2 @if($reasonValue !== ServiceCaseCloseReasonForClosing::ReferenceNumberPending->value && $reasonValue !== ServiceCaseCloseReasonForClosing::SerialNumberPending->value) d-none @endif"
             data-workspace-close-field-group="expected_from">
            <label for="workspace_close_expected_from" class="form-label workspace-action-field-label">
                Expected From <span class="text-danger">*</span>
            </label>
            <select name="expected_from"
                    id="workspace_close_expected_from"
                    class="form-select form-select-sm @error('expected_from') is-invalid @enderror"
                    @disabled($selectedAction !== WorkspaceActionType::Close)>
                <option value="" disabled @selected($expectedFromValue === '')>Select</option>
                <option value="customer" @selected($expectedFromValue === 'customer')>Customer</option>
                <option value="admin" @selected($expectedFromValue === 'admin')>Admin</option>
                <option value="distributor" @selected($expectedFromValue === 'distributor')>Distributor</option>
            </select>
            @error('expected_from')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-2 @if($reasonValue !== ServiceCaseCloseReasonForClosing::ReferenceNumberPending->value && $reasonValue !== ServiceCaseCloseReasonForClosing::SerialNumberPending->value) d-none @endif"
             data-workspace-close-field-group="expected_date">
            <label for="workspace_close_expected_date" class="form-label workspace-action-field-label">Expected Date</label>
            <input type="date"
                   name="expected_date"
                   id="workspace_close_expected_date"
                   value="{{ $expectedDateValue }}"
                   class="form-control form-control-sm @error('expected_date') is-invalid @enderror"
                   @disabled($selectedAction !== WorkspaceActionType::Close)>
            @error('expected_date')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="workspace-close-cnr-communication @if($reasonValue !== ServiceCaseCloseReasonForClosing::CustomerNotResponding->value) d-none @endif"
             data-workspace-close-field-group="cnr_communication">
            <fieldset class="workspace-close-cnr-communication-fieldset mb-0"
                      aria-label="Customer communication">
                <legend class="form-label workspace-action-field-label mb-1">
                    Select Communication <span class="text-danger">*</span>
                </legend>
                <div class="workspace-close-notification-options">
                    @foreach([
                        ServiceCaseCloseNotificationPreference::WhatsApp,
                        ServiceCaseCloseNotificationPreference::Email,
                        ServiceCaseCloseNotificationPreference::Both,
                    ] as $preference)
                        <div class="form-check">
                            <input class="form-check-input"
                                   type="radio"
                                   name="cnr_communication_preference"
                                   value="{{ $preference->value }}"
                                   id="workspace_close_cnr_{{ $preference->value }}"
                                   @checked($cnrCommunicationPreferenceValue === $preference->value)
                                   @disabled($selectedAction !== WorkspaceActionType::Close)>
                            <label class="form-check-label" for="workspace_close_cnr_{{ $preference->value }}">
                                {{ $preference->label() }}
                            </label>
                        </div>
                    @endforeach
                </div>
                @error('cnr_communication_preference')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
            </fieldset>

            <p class="workspace-close-cnr-template-note text-muted small mt-2 mb-0">
                Template: <strong>Final Reminder Before Closure</strong>
            </p>
            <p class="workspace-close-cnr-template-note text-muted small mb-0">
                The selected communication will be sent before this case is closed.
            </p>
        </div>

        <div class="mb-2 @if($reasonValue !== ServiceCaseCloseReasonForClosing::DuplicateCase->value) d-none @endif"
             data-workspace-close-field-group="existing_case_id">
            <label for="workspace_close_existing_case_id" class="form-label workspace-action-field-label">
                Existing Case ID <span class="text-danger">*</span>
            </label>
            <input type="text"
                   name="existing_case_id"
                   id="workspace_close_existing_case_id"
                   value="{{ $existingCaseIdValue }}"
                   class="form-control form-control-sm @error('existing_case_id') is-invalid @enderror"
                   @disabled($selectedAction !== WorkspaceActionType::Close)>
            @error('existing_case_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-2 @if($reasonValue !== ServiceCaseCloseReasonForClosing::ReplacementIssued->value) d-none @endif"
             data-workspace-close-field-group="replacement_order_id">
            <label for="workspace_close_replacement_order_id" class="form-label workspace-action-field-label">
                Replacement Order ID <span class="text-danger">*</span>
            </label>
            <input type="text"
                   name="replacement_order_id"
                   id="workspace_close_replacement_order_id"
                   value="{{ $replacementOrderIdValue }}"
                   class="form-control form-control-sm @error('replacement_order_id') is-invalid @enderror"
                   @disabled($selectedAction !== WorkspaceActionType::Close)>
            @error('replacement_order_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-2 @if($reasonValue !== ServiceCaseCloseReasonForClosing::ApprovedByAdmin->value) d-none @endif"
             data-workspace-close-field-group="approval_reference">
            <label for="workspace_close_approval_reference" class="form-label workspace-action-field-label">
                Approval Reference <span class="text-danger">*</span>
            </label>
            <input type="text"
                   name="approval_reference"
                   id="workspace_close_approval_reference"
                   value="{{ $approvalReferenceValue }}"
                   class="form-control form-control-sm @error('approval_reference') is-invalid @enderror"
                   @disabled($selectedAction !== WorkspaceActionType::Close)>
            @error('approval_reference')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    @error('reference_no')
        <div class="text-danger small mt-2">{{ $message }}</div>
    @enderror
    @error('serial_number')
        <div class="text-danger small mt-2">{{ $message }}</div>
    @enderror
    @error('transaction_id')
        <div class="text-danger small mt-2">{{ $message }}</div>
    @enderror
    @error('remarks')
        <div class="text-danger small mt-2">{{ $message }}</div>
    @enderror

    <fieldset class="workspace-action-notify workspace-action-notify--compact mt-2 mb-0 @if(! $showsNotification) d-none @endif"
              aria-label="Customer notification"
              data-workspace-close-notification>
        <legend class="form-label workspace-action-field-label mb-1">Notify Customer</legend>
        <div class="workspace-close-notification-options">
            @foreach($closeNotificationPreferences as $preference)
                <div class="form-check">
                    <input class="form-check-input"
                           type="radio"
                           name="notification_preference"
                           value="{{ $preference->value }}"
                           id="workspace_close_notify_{{ $preference->value }}"
                           @checked($notificationPreferenceValue === $preference->value)
                           @disabled($selectedAction !== WorkspaceActionType::Close)>
                    <label class="form-check-label" for="workspace_close_notify_{{ $preference->value }}">
                        {{ $preference->label() }}
                    </label>
                </div>
            @endforeach
        </div>
        @error('notification_preference')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </fieldset>
</div>
