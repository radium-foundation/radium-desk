<div class="ira-learning-center" data-ira-learning-center>
    <div class="ira-learning-center__toolbar card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div>
                    <h2 class="h6 mb-0">Needs Human Action</h2>
                    <p class="text-muted small mb-0">Teach IRA with Assign, Classification, Importance, or Ignore.</p>
                </div>
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="ira-select-all" data-ira-select-all>
                    <label class="form-check-label small" for="ira-select-all">Select all</label>
                </div>
            </div>

            <form method="POST"
                  action="{{ route('admin.incoming-emails.learning.apply') }}"
                  class="ira-learning-center__bulk"
                  data-ira-bulk-form>
                @csrf
                <div data-ira-selected-inputs></div>

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="ira-bulk-action">Bulk action</label>
                        <select id="ira-bulk-action" name="action" class="form-select form-select-sm" data-ira-bulk-action required>
                            <option value="">Choose…</option>
                            <option value="assign">Assign</option>
                            <option value="classification">Classification</option>
                            <option value="importance">Importance</option>
                            <option value="ignore">Ignore</option>
                        </select>
                    </div>

                    <div class="col-md-3" data-ira-panel="assign" hidden>
                        <label class="form-label small mb-1" for="ira-bulk-assignee">Choose user</label>
                        <select id="ira-bulk-assignee" name="assignee_user_id" class="form-select form-select-sm">
                            <option value="">Select assignee</option>
                            @foreach($assignableUsers as $user)
                                <option value="{{ $user->id }}">{{ method_exists($user, 'firstName') ? $user->firstName() : $user->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3" data-ira-panel="classification" hidden>
                        <label class="form-label small mb-1" for="ira-bulk-classification">Classification</label>
                        <select id="ira-bulk-classification" name="classification" class="form-select form-select-sm">
                            @foreach($operatorClassifications as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3" data-ira-panel="importance" hidden>
                        <label class="form-label small mb-1" for="ira-bulk-importance">Importance</label>
                        <select id="ira-bulk-importance" name="importance" class="form-select form-select-sm">
                            @foreach($importanceOptions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3" data-ira-panel="ignore" hidden>
                        <label class="form-label small mb-1" for="ira-bulk-ignore">Ignore</label>
                        <select id="ira-bulk-ignore" name="ignore_action" class="form-select form-select-sm">
                            @foreach($ignoreActions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="ira-bulk-scope">Learning scope</label>
                        <select id="ira-bulk-scope" name="scope" class="form-select form-select-sm" required>
                            @foreach($learningScopes as $scope)
                                <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <button type="submit" class="btn btn-sm btn-primary w-100" data-ira-bulk-apply disabled>
                            Apply
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if($cards === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted">No emails need human action.</div>
        </div>
    @else
        <div class="ira-learning-center__cards">
            @foreach($cards as $card)
                @include('admin.incoming-emails.partials.learning-card', ['card' => $card])
            @endforeach
        </div>
    @endif
</div>
