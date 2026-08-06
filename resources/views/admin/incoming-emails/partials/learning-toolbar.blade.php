{{-- Teaching toolbar — never leaves Needs Human by itself --}}
<form method="POST"
      action="{{ route('admin.incoming-emails.learning.apply') }}"
      class="ira-lc-toolbar"
      data-ira-bulk-form
      data-ira-toolbar
      data-ira-toolbar-kind="teach"
      hidden>
    @csrf
    <input type="hidden" name="return_queue" value="{{ $queue->value }}">
    <div data-ira-selected-inputs></div>

    <div class="ira-lc-toolbar__inner">
        <label class="ira-lc-toolbar__select-all">
            <input type="checkbox" class="form-check-input m-0" data-ira-select-all>
            <span>Select all</span>
            <span class="ira-lc-toolbar__selected" data-ira-selected-count>0</span>
        </label>

        <span class="ira-lc-toolbar__mode">Teach</span>

        <select name="action" class="form-select form-select-sm ira-lc-toolbar__select" data-ira-bulk-action required>
            <option value="">Teach…</option>
            <option value="assign">Owner</option>
            <option value="classification">Classification</option>
            <option value="importance">Importance</option>
        </select>

        <select name="assignee_user_id"
                class="form-select form-select-sm ira-lc-toolbar__select"
                data-ira-panel="assign"
                hidden
                disabled>
            <option value="">Choose user</option>
            @foreach($assignableUsers as $user)
                <option value="{{ $user->id }}">{{ $user->firstName() }}</option>
            @endforeach
        </select>

        <select name="classification"
                class="form-select form-select-sm ira-lc-toolbar__select"
                data-ira-panel="classification"
                hidden
                disabled>
            @foreach($operatorClassifications as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>

        <select name="importance"
                class="form-select form-select-sm ira-lc-toolbar__select"
                data-ira-panel="importance"
                hidden
                disabled>
            @foreach($importanceOptions as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>

        <select name="scope" class="form-select form-select-sm ira-lc-toolbar__select" required>
            @foreach($learningScopes as $scope)
                <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-sm btn-outline-dark ira-lc-toolbar__apply" data-ira-bulk-apply>
            Teach
        </button>
    </div>
</form>

{{-- Disposition toolbar — required to leave Needs Human (except Keep Pending) --}}
@if($queue === \App\Enums\IncomingEmailIntakeQueue::NeedsHuman)
<form method="POST"
      action="{{ route('admin.incoming-emails.disposition.apply') }}"
      class="ira-lc-toolbar ira-lc-toolbar--dispose"
      data-ira-disposition-form
      data-ira-disposition-toolbar
      hidden>
    @csrf
    <input type="hidden" name="return_queue" value="{{ $queue->value }}">
    <div data-ira-disposition-inputs></div>

    <div class="ira-lc-toolbar__inner">
        <span class="ira-lc-toolbar__mode ira-lc-toolbar__mode--dispose">Dispose</span>

        <select name="disposition"
                class="form-select form-select-sm ira-lc-toolbar__select"
                data-ira-disposition-action
                required>
            <option value="">Disposition…</option>
            <option value="create_case">Create Service Case</option>
            <option value="link_case">Link Existing Case</option>
            <option value="ignore">Ignore</option>
            <option value="spam">Spam</option>
            <option value="promotion">Promotion</option>
            <option value="auto_processed">Auto Processed</option>
            <option value="keep_pending">Keep Pending</option>
        </select>

        <select name="assignee_user_id"
                class="form-select form-select-sm ira-lc-toolbar__select"
                data-ira-disp-panel="create_case"
                hidden
                disabled>
            <option value="">Owner (optional)</option>
            @foreach($assignableUsers as $user)
                <option value="{{ $user->id }}">{{ $user->firstName() }}</option>
            @endforeach
        </select>

        <input type="text"
               name="case_reference"
               class="form-control form-control-sm ira-lc-toolbar__select"
               placeholder="SC#####"
               data-ira-disp-panel="link_case"
               hidden
               disabled>

        <select name="ignore_variant"
                class="form-select form-select-sm ira-lc-toolbar__select"
                data-ira-disp-panel="ignore"
                hidden
                disabled>
            @foreach($ignoreDispositionVariants as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>

        <select name="keep_pending_reason"
                class="form-select form-select-sm ira-lc-toolbar__select"
                data-ira-disp-panel="keep_pending"
                hidden
                disabled>
            <option value="">Reason required…</option>
            @foreach($keepPendingReasons as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-sm btn-dark ira-lc-toolbar__apply">
            Complete
        </button>
    </div>
</form>
@endif
