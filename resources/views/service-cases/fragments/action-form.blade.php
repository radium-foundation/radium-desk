@php
    use App\Enums\WorkspaceActionType;
    use App\Support\Customer360\Customer360OverflowMenuLucideIcon;

    $selectedAction = $selectedAction ?? WorkspaceActionType::Assign;
    $formPayload = $formPayload ?? [];
    $capabilities = $actionCapabilities ?? ['assign' => false, 'close' => false, 'reopen' => false, 'escalate' => false];
    $bodyValue = old('body', $formPayload['body'] ?? $remarkBody ?? '');
    $assigneeValue = old('assigned_to_user_id', $formPayload['assigned_to_user_id'] ?? $incident->assigned_to_user_id);
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
            @include('service-cases.fragments.partials.close-case-v2-fields')
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
            <label for="workspace_action_remark"
                   class="form-label workspace-action-field-label"
                   data-workspace-action-remark-label>
                @if($selectedAction === WorkspaceActionType::Close)
                    Closing Summary <span class="text-danger">*</span>
                @else
                    Remark <span class="text-danger">*</span>
                @endif
            </label>
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
