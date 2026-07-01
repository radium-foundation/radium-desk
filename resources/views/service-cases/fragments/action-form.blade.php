@php
    use App\Enums\ServiceCaseCloseExceptionReason;
    use App\Enums\WorkspaceActionType;

    $selectedAction = $selectedAction ?? WorkspaceActionType::Assign;
    $formPayload = $formPayload ?? [];
    $capabilities = $actionCapabilities ?? ['assign' => false, 'close' => false, 'reopen' => false];
    $bodyValue = old('body', $formPayload['body'] ?? $remarkBody ?? '');
    $assigneeValue = old('assigned_to_user_id', $formPayload['assigned_to_user_id'] ?? $incident->assigned_to_user_id);
    $reopenReasonValue = old('reopen_reason', $formPayload['reopen_reason'] ?? '');
    $serialUnavailable = old('serial_number_unavailable', $formPayload['serial_number_unavailable'] ?? false);
    $referenceUnavailable = old('reference_number_unavailable', $formPayload['reference_number_unavailable'] ?? false);
    $exceptionReasonValue = old('exception_reason', $formPayload['exception_reason'] ?? '');
    $exceptionCustomValue = old('exception_reason_custom', $formPayload['exception_reason_custom'] ?? '');
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="action"
      data-workspace-action-dialog>
    @csrf
    @method('PATCH')
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    <input type="hidden" name="action_type" value="{{ $selectedAction->value }}" data-workspace-action-type-input>

    <div class="modal-header border-0 pb-0">
        <div>
            <h2 class="modal-title h4 mb-1" id="workspaceActionModalLabel">Service Case Action</h2>
            <p class="text-muted small mb-0">{{ $incident->display_reference }}</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body workspace-action-dialog-body">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        <div class="workspace-action-cards mb-4" role="tablist" aria-label="Select action">
            @if($capabilities['assign'])
                <button type="button"
                        class="workspace-action-card @if($selectedAction === WorkspaceActionType::Assign) is-active @endif"
                        data-workspace-action-card="assign"
                        aria-pressed="{{ $selectedAction === WorkspaceActionType::Assign ? 'true' : 'false' }}">
                    <span class="workspace-action-card-icon" aria-hidden="true">👤</span>
                    <span class="workspace-action-card-label">Assign</span>
                </button>
            @endif
            @if($capabilities['close'])
                <button type="button"
                        class="workspace-action-card @if($selectedAction === WorkspaceActionType::Close) is-active @endif"
                        data-workspace-action-card="close"
                        aria-pressed="{{ $selectedAction === WorkspaceActionType::Close ? 'true' : 'false' }}">
                    <span class="workspace-action-card-icon" aria-hidden="true">✅</span>
                    <span class="workspace-action-card-label">Close</span>
                </button>
            @endif
            @if($capabilities['reopen'])
                <button type="button"
                        class="workspace-action-card @if($selectedAction === WorkspaceActionType::Reopen) is-active @endif"
                        data-workspace-action-card="reopen"
                        aria-pressed="{{ $selectedAction === WorkspaceActionType::Reopen ? 'true' : 'false' }}">
                    <span class="workspace-action-card-icon" aria-hidden="true">↩</span>
                    <span class="workspace-action-card-label">Reopen</span>
                </button>
            @endif
        </div>

        <div class="workspace-action-panel @if($selectedAction !== WorkspaceActionType::Assign) d-none @endif"
             data-workspace-action-panel="assign">
            <div class="mb-3">
                <label for="workspace_action_assignee" class="form-label">Assign To <span class="text-danger">*</span></label>
                <select name="assigned_to_user_id"
                        id="workspace_action_assignee"
                        class="form-select @error('assigned_to_user_id') is-invalid @enderror"
                        @disabled($selectedAction !== WorkspaceActionType::Assign)>
                    <option value="" disabled @selected($assigneeValue === null)>Select assignee</option>
                    @foreach($reassignableAdmins as $adminUser)
                        <option value="{{ $adminUser->id }}" @selected((int) $assigneeValue === $adminUser->id)>
                            {{ $adminUser->firstName() }}
                        </option>
                    @endforeach
                </select>
                @error('assigned_to_user_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="workspace-action-panel @if($selectedAction !== WorkspaceActionType::Close) d-none @endif"
             data-workspace-action-panel="close">
            <fieldset class="mb-3">
                <legend class="form-label mb-2">Exceptions</legend>
                <div class="form-check">
                    <input class="form-check-input"
                           type="checkbox"
                           name="serial_number_unavailable"
                           value="1"
                           id="workspace_action_serial_unavailable"
                           @checked($serialUnavailable)
                           @disabled($selectedAction !== WorkspaceActionType::Close)>
                    <label class="form-check-label" for="workspace_action_serial_unavailable">
                        Serial Number unavailable
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input"
                           type="checkbox"
                           name="reference_number_unavailable"
                           value="1"
                           id="workspace_action_reference_unavailable"
                           @checked($referenceUnavailable)
                           @disabled($selectedAction !== WorkspaceActionType::Close)>
                    <label class="form-check-label" for="workspace_action_reference_unavailable">
                        Reference Number unavailable
                    </label>
                </div>
            </fieldset>

            <div class="workspace-action-exception-fields @if(! $serialUnavailable && ! $referenceUnavailable) d-none @endif"
                 data-workspace-exception-fields>
                <div class="mb-3">
                    <label for="workspace_action_exception_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                    <select name="exception_reason"
                            id="workspace_action_exception_reason"
                            class="form-select @error('exception_reason') is-invalid @enderror"
                            @disabled($selectedAction !== WorkspaceActionType::Close)>
                        <option value="" disabled @selected($exceptionReasonValue === '')>Select reason</option>
                        @foreach($exceptionReasons as $reason)
                            <option value="{{ $reason->value }}" @selected($exceptionReasonValue === $reason->value)>
                                {{ $reason->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('exception_reason')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3 @if($exceptionReasonValue !== ServiceCaseCloseExceptionReason::Other->value) d-none @endif"
                     data-workspace-exception-custom>
                    <label for="workspace_action_exception_custom" class="form-label">Custom Remark <span class="text-danger">*</span></label>
                    <textarea name="exception_reason_custom"
                              id="workspace_action_exception_custom"
                              rows="2"
                              class="form-control @error('exception_reason_custom') is-invalid @enderror"
                              @disabled($selectedAction !== WorkspaceActionType::Close)>{{ $exceptionCustomValue }}</textarea>
                    @error('exception_reason_custom')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @error('reference_no')
                <div class="text-danger small mb-2">{{ $message }}</div>
            @enderror
            @error('serial_number')
                <div class="text-danger small mb-2">{{ $message }}</div>
            @enderror
            @error('transaction_id')
                <div class="text-danger small mb-2">{{ $message }}</div>
            @enderror
            @error('remarks')
                <div class="text-danger small mb-2">{{ $message }}</div>
            @enderror

            <fieldset class="mb-0 mt-3" aria-label="Customer notification">
                <legend class="form-label mb-2">Notify Customer</legend>
                <div class="form-check">
                    <input class="form-check-input"
                           type="checkbox"
                           name="notify_whatsapp"
                           value="1"
                           id="workspace_action_notify_whatsapp"
                           disabled
                           aria-disabled="true">
                    <label class="form-check-label text-muted" for="workspace_action_notify_whatsapp">
                        WhatsApp <span class="small">(coming soon)</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input"
                           type="checkbox"
                           name="notify_email"
                           value="1"
                           id="workspace_action_notify_email"
                           disabled
                           aria-disabled="true">
                    <label class="form-check-label text-muted" for="workspace_action_notify_email">
                        Email <span class="small">(coming soon)</span>
                    </label>
                </div>
            </fieldset>
        </div>

        <div class="workspace-action-panel @if($selectedAction !== WorkspaceActionType::Reopen) d-none @endif"
             data-workspace-action-panel="reopen">
            <div class="mb-3">
                <label for="workspace_action_reopen_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea name="reopen_reason"
                          id="workspace_action_reopen_reason"
                          rows="2"
                          class="form-control @error('reopen_reason') is-invalid @enderror"
                          @disabled($selectedAction !== WorkspaceActionType::Reopen)>{{ $reopenReasonValue }}</textarea>
                @error('reopen_reason')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="workspace_action_reopen_assignee" class="form-label">Assign To <span class="text-muted">(optional)</span></label>
                <select name="assigned_to_user_id"
                        id="workspace_action_reopen_assignee"
                        class="form-select @error('assigned_to_user_id') is-invalid @enderror"
                        @disabled($selectedAction !== WorkspaceActionType::Reopen)>
                    <option value="" @selected($assigneeValue === null || $assigneeValue === '')>Keep current assignee</option>
                    @foreach($reassignableAdmins as $adminUser)
                        <option value="{{ $adminUser->id }}" @selected((int) $assigneeValue === $adminUser->id)>
                            {{ $adminUser->firstName() }}
                        </option>
                    @endforeach
                </select>
                @error('assigned_to_user_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="mt-4">
            <label for="workspace_action_remark" class="form-label">Remark <span class="text-danger">*</span></label>
            <textarea name="body"
                      id="workspace_action_remark"
                      rows="4"
                      class="form-control workspace-action-remark @error('body') is-invalid @enderror"
                      data-mention-textarea
                      required>{{ $bodyValue }}</textarea>
            @error('body')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary px-4">Confirm</button>
    </div>
</form>
