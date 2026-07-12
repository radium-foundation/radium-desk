@php
    use App\Enums\ServiceCaseCloseExceptionReason;
    use App\Enums\WorkspaceActionType;
    use App\Support\Customer360\Customer360OverflowMenuLucideIcon;

    $selectedAction = $selectedAction ?? WorkspaceActionType::Assign;
    $formPayload = $formPayload ?? [];
    $capabilities = $actionCapabilities ?? ['assign' => false, 'close' => false, 'reopen' => false, 'escalate' => false];
    $bodyValue = old('body', $formPayload['body'] ?? $remarkBody ?? '');
    $assigneeValue = old('assigned_to_user_id', $formPayload['assigned_to_user_id'] ?? $incident->assigned_to_user_id);
    $serialUnavailable = old('serial_number_unavailable', $formPayload['serial_number_unavailable'] ?? false);
    $referenceUnavailable = old('reference_number_unavailable', $formPayload['reference_number_unavailable'] ?? false);
    $serialReasonValue = old('serial_exception_reason', $formPayload['serial_exception_reason'] ?? '');
    $serialCustomValue = old('serial_exception_reason_custom', $formPayload['serial_exception_reason_custom'] ?? '');
    $referenceReasonValue = old('reference_exception_reason', $formPayload['reference_exception_reason'] ?? '');
    $referenceCustomValue = old('reference_exception_reason_custom', $formPayload['reference_exception_reason_custom'] ?? '');
    $exceptionDate = now()->format('Ymd');
    $mentionListId = 'mention-users-action-'.md5($incident::class.$incident->getKey());

    $order = $incident->order;
    $subtitlePrimary = filled($order?->order_id)
        ? $incident->display_reference.' • '.$order->order_id
        : $incident->display_reference;
    $subtitleCustomer = filled($order?->customer_name) ? $order->customer_name : null;

    $remarkPlaceholder = match ($selectedAction) {
        WorkspaceActionType::Assign => 'Reason for reassignment…',
        WorkspaceActionType::Escalate => 'Explain why this requires escalation…',
        WorkspaceActionType::Close => 'Closing summary…',
        WorkspaceActionType::Reopen => 'Reason for reopening…',
    };

    $submitLabel = match ($selectedAction) {
        WorkspaceActionType::Assign => 'Assign Engineer',
        WorkspaceActionType::Escalate => 'Escalate Case',
        WorkspaceActionType::Close => 'Close Case',
        WorkspaceActionType::Reopen => 'Reopen Case',
    };

    $submitAccent = match ($selectedAction) {
        WorkspaceActionType::Assign => 'assign',
        WorkspaceActionType::Escalate => 'escalate',
        WorkspaceActionType::Close => 'close',
        WorkspaceActionType::Reopen => 'reopen',
    };
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="action"
      data-workspace-action-dialog
      class="workspace-action-dialog">
    @csrf
    @method('PATCH')
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    <input type="hidden" name="action_type" value="{{ $selectedAction->value }}" data-workspace-action-type-input>

    <div class="modal-header workspace-action-dialog-header border-0 pb-0">
        <div>
            <h2 class="modal-title h5 mb-0" id="workspaceActionModalLabel">Manage Case</h2>
            <p class="workspace-action-dialog-subtitle text-muted small mb-0">
                <span class="d-block">{{ $subtitlePrimary }}</span>
                @if(filled($subtitleCustomer))
                    <span class="d-block workspace-action-dialog-subtitle-meta">{{ $subtitleCustomer }}</span>
                @endif
            </p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body workspace-action-dialog-body">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-2" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        <div class="workspace-action-segments mb-2" role="tablist" aria-label="Select action">
            @if($capabilities['assign'])
                <button type="button"
                        class="workspace-action-segment workspace-action-segment--assign @if($selectedAction === WorkspaceActionType::Assign) is-active @endif"
                        data-workspace-action-card="assign"
                        role="tab"
                        aria-selected="{{ $selectedAction === WorkspaceActionType::Assign ? 'true' : 'false' }}"
                        aria-pressed="{{ $selectedAction === WorkspaceActionType::Assign ? 'true' : 'false' }}">
                    {!! Customer360OverflowMenuLucideIcon::render('user-plus', 'workspace-action-segment-icon') !!}
                    <span>Assign</span>
                </button>
            @endif
            @if($capabilities['escalate'])
                <button type="button"
                        class="workspace-action-segment workspace-action-segment--escalate @if($selectedAction === WorkspaceActionType::Escalate) is-active @endif"
                        data-workspace-action-card="escalate"
                        role="tab"
                        aria-selected="{{ $selectedAction === WorkspaceActionType::Escalate ? 'true' : 'false' }}"
                        aria-pressed="{{ $selectedAction === WorkspaceActionType::Escalate ? 'true' : 'false' }}">
                    {!! Customer360OverflowMenuLucideIcon::render('arrow-up-circle', 'workspace-action-segment-icon') !!}
                    <span>Escalate</span>
                </button>
            @endif
            @if($capabilities['close'])
                <button type="button"
                        class="workspace-action-segment workspace-action-segment--close @if($selectedAction === WorkspaceActionType::Close) is-active @endif"
                        data-workspace-action-card="close"
                        role="tab"
                        aria-selected="{{ $selectedAction === WorkspaceActionType::Close ? 'true' : 'false' }}"
                        aria-pressed="{{ $selectedAction === WorkspaceActionType::Close ? 'true' : 'false' }}">
                    {!! Customer360OverflowMenuLucideIcon::render('check-circle', 'workspace-action-segment-icon') !!}
                    <span>Close</span>
                </button>
            @endif
            @if($capabilities['reopen'])
                <button type="button"
                        class="workspace-action-segment workspace-action-segment--reopen @if($selectedAction === WorkspaceActionType::Reopen) is-active @endif"
                        data-workspace-action-card="reopen"
                        role="tab"
                        aria-selected="{{ $selectedAction === WorkspaceActionType::Reopen ? 'true' : 'false' }}"
                        aria-pressed="{{ $selectedAction === WorkspaceActionType::Reopen ? 'true' : 'false' }}">
                    {!! Customer360OverflowMenuLucideIcon::render('rotate-ccw', 'workspace-action-segment-icon') !!}
                    <span>Reopen</span>
                </button>
            @endif
        </div>

        <div class="workspace-action-descriptions mb-2" aria-live="polite">
            @if($capabilities['assign'])
                <p class="workspace-action-description text-muted small mb-0 @if($selectedAction !== WorkspaceActionType::Assign) d-none @endif"
                   data-workspace-action-description="assign">
                    Transfer ownership to another engineer.
                </p>
            @endif
            @if($capabilities['escalate'])
                <p class="workspace-action-description text-muted small mb-0 @if($selectedAction !== WorkspaceActionType::Escalate) d-none @endif"
                   data-workspace-action-description="escalate">
                    Send this case to the escalation team.
                </p>
            @endif
            @if($capabilities['close'])
                <p class="workspace-action-description text-muted small mb-0 @if($selectedAction !== WorkspaceActionType::Close) d-none @endif"
                   data-workspace-action-description="close">
                    Complete and close this case.
                </p>
            @endif
            @if($capabilities['reopen'])
                <p class="workspace-action-description text-muted small mb-0 @if($selectedAction !== WorkspaceActionType::Reopen) d-none @endif"
                   data-workspace-action-description="reopen">
                    Reopen this completed case.
                </p>
            @endif
        </div>

        <div class="workspace-action-panel @if($selectedAction !== WorkspaceActionType::Assign) d-none @endif"
             data-workspace-action-panel="assign"
             role="tabpanel">
            <div class="mb-0">
                <label for="workspace_action_assignee" class="form-label workspace-action-field-label">Engineer <span class="text-danger">*</span></label>
                <select name="assigned_to_user_id"
                        id="workspace_action_assignee"
                        class="form-select form-select-sm @error('assigned_to_user_id') is-invalid @enderror"
                        @disabled($selectedAction !== WorkspaceActionType::Assign)>
                    <option value="" disabled @selected($assigneeValue === null || $assigneeValue === '')>Select</option>
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
             data-workspace-action-panel="close"
             role="tabpanel">
            <details class="workspace-action-exceptions mb-0">
                <summary class="workspace-action-exceptions-summary">Exceptions</summary>
                <div class="workspace-action-exceptions-body">
                    <div class="form-check">
                        <input class="form-check-input"
                               type="checkbox"
                               name="serial_number_unavailable"
                               value="1"
                               id="workspace_action_serial_unavailable"
                               @checked($serialUnavailable)
                               @disabled($selectedAction !== WorkspaceActionType::Close)>
                        <label class="form-check-label" for="workspace_action_serial_unavailable">
                            Serial Number not available
                        </label>
                    </div>

                    <div class="workspace-action-exception-detail @if(! $serialUnavailable) d-none @endif"
                         data-workspace-exception-detail="serial">
                        <div class="mb-2">
                            <label for="workspace_action_serial_reason" class="form-label workspace-action-field-label">Reason <span class="text-danger">*</span></label>
                            <select name="serial_exception_reason"
                                    id="workspace_action_serial_reason"
                                    class="form-select form-select-sm @error('serial_exception_reason') is-invalid @enderror"
                                    @disabled($selectedAction !== WorkspaceActionType::Close)>
                                <option value="" disabled @selected($serialReasonValue === '')>Select reason</option>
                                @foreach($exceptionReasons as $reason)
                                    <option value="{{ $reason->value }}" @selected($serialReasonValue === $reason->value)>
                                        {{ $reason->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('serial_exception_reason')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-2 @if($serialReasonValue !== ServiceCaseCloseExceptionReason::Other->value) d-none @endif"
                             data-workspace-exception-custom="serial">
                            <label for="workspace_action_serial_custom" class="form-label workspace-action-field-label">Custom Remark <span class="text-danger">*</span></label>
                            <textarea name="serial_exception_reason_custom"
                                      id="workspace_action_serial_custom"
                                      rows="2"
                                      class="form-control form-control-sm @error('serial_exception_reason_custom') is-invalid @enderror"
                                      @disabled($selectedAction !== WorkspaceActionType::Close)>{{ $serialCustomValue }}</textarea>
                            @error('serial_exception_reason_custom')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <p class="workspace-action-exception-preview small text-muted mb-2">
                            System generates: <span class="font-monospace">EXS-{{ $exceptionDate }}-0001</span>
                        </p>
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
                            Reference Number not available
                        </label>
                    </div>

                    <div class="workspace-action-exception-detail @if(! $referenceUnavailable) d-none @endif"
                         data-workspace-exception-detail="reference">
                        <div class="mb-2">
                            <label for="workspace_action_reference_reason" class="form-label workspace-action-field-label">Reason <span class="text-danger">*</span></label>
                            <select name="reference_exception_reason"
                                    id="workspace_action_reference_reason"
                                    class="form-select form-select-sm @error('reference_exception_reason') is-invalid @enderror"
                                    @disabled($selectedAction !== WorkspaceActionType::Close)>
                                <option value="" disabled @selected($referenceReasonValue === '')>Select reason</option>
                                @foreach($exceptionReasons as $reason)
                                    <option value="{{ $reason->value }}" @selected($referenceReasonValue === $reason->value)>
                                        {{ $reason->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('reference_exception_reason')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-2 @if($referenceReasonValue !== ServiceCaseCloseExceptionReason::Other->value) d-none @endif"
                             data-workspace-exception-custom="reference">
                            <label for="workspace_action_reference_custom" class="form-label workspace-action-field-label">Custom Remark <span class="text-danger">*</span></label>
                            <textarea name="reference_exception_reason_custom"
                                      id="workspace_action_reference_custom"
                                      rows="2"
                                      class="form-control form-control-sm @error('reference_exception_reason_custom') is-invalid @enderror"
                                      @disabled($selectedAction !== WorkspaceActionType::Close)>{{ $referenceCustomValue }}</textarea>
                            @error('reference_exception_reason_custom')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <p class="workspace-action-exception-preview small text-muted mb-0">
                            System generates: <span class="font-monospace">EXR-{{ $exceptionDate }}-0001</span>
                        </p>
                    </div>
                </div>
            </details>

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

            <fieldset class="workspace-action-notify workspace-action-notify--compact mt-2 mb-0" aria-label="Customer notification">
                <legend class="form-label workspace-action-field-label mb-1">Notify Customer</legend>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input"
                               type="checkbox"
                               name="notify_whatsapp"
                               value="1"
                               id="workspace_action_notify_whatsapp"
                               @disabled($selectedAction !== WorkspaceActionType::Close)>
                        <label class="form-check-label" for="workspace_action_notify_whatsapp">WhatsApp</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input"
                               type="checkbox"
                               name="notify_email"
                               value="1"
                               id="workspace_action_notify_email"
                               @disabled($selectedAction !== WorkspaceActionType::Close)>
                        <label class="form-check-label" for="workspace_action_notify_email">Email</label>
                    </div>
                </div>
            </fieldset>
        </div>

        <div class="workspace-action-notify-notes mt-2 mb-0" aria-live="polite">
            @if($capabilities['assign'])
                <p class="workspace-action-notify-note text-muted small mb-0 @if($selectedAction !== WorkspaceActionType::Assign) d-none @endif"
                   data-workspace-action-notify-note="assign">
                    The assigned engineer will be notified.
                </p>
            @endif
            @if($capabilities['escalate'])
                <p class="workspace-action-notify-note text-muted small mb-0 @if($selectedAction !== WorkspaceActionType::Escalate) d-none @endif"
                   data-workspace-action-notify-note="escalate">
                    The escalation team will be notified.
                </p>
            @endif
            @if($capabilities['close'])
                <p class="workspace-action-notify-note text-muted small mb-0 @if($selectedAction !== WorkspaceActionType::Close) d-none @endif"
                   data-workspace-action-notify-note="close">
                    Closure notifications follow existing workflow.
                </p>
            @endif
        </div>

        <div class="workspace-action-remark-section mt-2">
            <label for="workspace_action_remark" class="form-label workspace-action-field-label">Remark <span class="text-danger">*</span></label>
            <textarea name="body"
                      id="workspace_action_remark"
                      rows="3"
                      class="form-control form-control-sm workspace-action-remark @error('body') is-invalid @enderror"
                      placeholder="{{ $remarkPlaceholder }}"
                      data-workspace-action-remark
                      data-mention-textarea
                      data-mention-list="{{ $mentionListId }}"
                      required>{{ $bodyValue }}</textarea>
            <datalist id="{{ $mentionListId }}">
                @foreach($mentionUsers as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            @error('body')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="modal-footer workspace-action-dialog-footer border-0 pt-0">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit"
                class="btn btn-sm workspace-action-submit workspace-action-submit--{{ $submitAccent }} px-4"
                data-workspace-action-submit>
            {{ $submitLabel }}
        </button>
    </div>
</form>
