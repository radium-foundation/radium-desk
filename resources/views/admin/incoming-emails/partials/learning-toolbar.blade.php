<form method="POST"
      action="{{ route('admin.incoming-emails.learning.apply') }}"
      class="ira-lc-toolbar"
      data-ira-bulk-form
      data-ira-toolbar
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

        <select name="action" class="form-select form-select-sm ira-lc-toolbar__select" data-ira-bulk-action required>
            <option value="">Action…</option>
            <option value="assign">Assign</option>
            <option value="classification">Classification</option>
            <option value="importance">Importance</option>
            <option value="move">Move To</option>
            <option value="ignore">Ignore</option>
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

        <select name="move_to"
                class="form-select form-select-sm ira-lc-toolbar__select"
                data-ira-panel="move"
                hidden
                disabled>
            <option value="promotion">Promotions</option>
            <option value="spam">Spam</option>
            <option value="automatic">Automatic</option>
        </select>

        <select name="ignore_action"
                class="form-select form-select-sm ira-lc-toolbar__select"
                data-ira-panel="ignore"
                hidden
                disabled>
            @foreach($ignoreActions as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>

        <select name="scope" class="form-select form-select-sm ira-lc-toolbar__select" required>
            @foreach($learningScopes as $scope)
                <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-sm btn-dark ira-lc-toolbar__apply" data-ira-bulk-apply>
            Apply
        </button>
    </div>
</form>
