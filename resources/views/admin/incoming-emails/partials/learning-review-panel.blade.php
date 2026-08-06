@php
    use App\Enums\IncomingEmailIntakeQueue;

    $isSpamQueuePanel = $queue === IncomingEmailIntakeQueue::Spam;
    $supportsReviewPanel = in_array($queue, [
        IncomingEmailIntakeQueue::NeedsHuman,
        IncomingEmailIntakeQueue::ReviewSuggested,
        IncomingEmailIntakeQueue::Spam,
    ], true);
@endphp

@if($supportsReviewPanel)
<form method="POST"
      action="{{ route('admin.incoming-emails.review.apply') }}"
      class="ira-lc-review"
      data-ira-review-form
      data-ira-review-panel
      hidden>
    @csrf
    <input type="hidden" name="return_queue" value="{{ $queue->value }}">
    <input type="hidden" name="message_ids[]" value="" data-ira-review-message-id>
    <input type="hidden" name="baseline_assignee_user_id" value="" data-ira-baseline="assignee">
    <input type="hidden" name="baseline_classification" value="" data-ira-baseline="classification">
    <input type="hidden" name="baseline_importance" value="" data-ira-baseline="importance">

    <div class="ira-lc-review__header">
        <div class="ira-lc-review__identity">
            <div class="ira-lc-review__eyebrow">Review</div>
            <div class="ira-lc-review__subject" data-ira-review-subject>Select an email</div>
            <div class="ira-lc-review__meta">
                <span data-ira-review-sender></span>
                <span class="ira-lc-review__preview" data-ira-review-preview></span>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ira-review-close>
            Close
        </button>
    </div>

    <div class="ira-lc-review__body">
        <section class="ira-lc-review__section" aria-labelledby="ira-lc-teach-heading">
            <div class="ira-lc-review__section-head">
                <h3 id="ira-lc-teach-heading" class="ira-lc-review__section-title">Teach IRA</h3>
                <span class="ira-lc-review__optional">Optional</span>
            </div>
            <div class="ira-lc-review__fields">
                <label class="ira-lc-review__field">
                    <span>Owner</span>
                    <select name="assignee_user_id"
                            class="form-select form-select-sm"
                            data-ira-review-teach="assignee">
                        <option value="">Keep current</option>
                        @foreach($assignableUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->firstName() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ira-lc-review__field">
                    <span>Classification</span>
                    <select name="classification"
                            class="form-select form-select-sm"
                            data-ira-review-teach="classification">
                        <option value="">Keep current</option>
                        @foreach($operatorClassifications as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ira-lc-review__field">
                    <span>Importance</span>
                    <select name="importance"
                            class="form-select form-select-sm"
                            data-ira-review-teach="importance">
                        <option value="">Keep current</option>
                        @foreach($importanceOptions as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ira-lc-review__field">
                    <span>Scope</span>
                    <select name="scope"
                            class="form-select form-select-sm"
                            data-ira-review-scope>
                        @foreach($learningScopes as $scope)
                            <option value="{{ $scope->value }}" @selected($scope->value === 'this_email')>
                                {{ $scope->label() }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        <section class="ira-lc-review__section ira-lc-review__section--dispose" aria-labelledby="ira-lc-dispose-heading">
            <div class="ira-lc-review__section-head">
                <h3 id="ira-lc-dispose-heading" class="ira-lc-review__section-title">Disposition</h3>
                <span class="ira-lc-review__required">Required</span>
            </div>
            <div class="ira-lc-review__fields">
                <label class="ira-lc-review__field">
                    <span>Action</span>
                    <select name="disposition"
                            class="form-select form-select-sm"
                            data-ira-review-disposition
                            required>
                        <option value="">Choose disposition…</option>
                        <option value="create_case">Create Service Case</option>
                        <option value="link_case">Link Existing Case</option>
                        @unless($isSpamQueuePanel)
                            <option value="ignore">Ignore</option>
                            <option value="spam">Spam</option>
                            <option value="promotion">Promotion</option>
                            <option value="auto_processed">Completed Automatically</option>
                            <option value="keep_pending">Keep Pending</option>
                        @endunless
                    </select>
                </label>

                <label class="ira-lc-review__field" data-ira-review-disp-panel="create_case" hidden>
                    <span>Case owner</span>
                    <select name="disposition_assignee_user_id"
                            class="form-select form-select-sm"
                            data-ira-review-disp-input="create_case"
                            disabled>
                        <option value="">Use taught / suggested owner</option>
                        @foreach($assignableUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->firstName() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ira-lc-review__field" data-ira-review-disp-panel="link_case" hidden>
                    <span>Case reference</span>
                    <input type="text"
                           name="case_reference"
                           class="form-control form-control-sm"
                           placeholder="SC#####"
                           data-ira-review-disp-input="link_case"
                           disabled>
                </label>

                @unless($isSpamQueuePanel)
                    <label class="ira-lc-review__field" data-ira-review-disp-panel="ignore" hidden>
                        <span>Ignore variant</span>
                        <select name="ignore_variant"
                                class="form-select form-select-sm"
                                data-ira-review-disp-input="ignore"
                                disabled>
                            @foreach($ignoreDispositionVariants as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="ira-lc-review__field" data-ira-review-disp-panel="keep_pending" hidden>
                        <span>Pending reason</span>
                        <select name="keep_pending_reason"
                                class="form-select form-select-sm"
                                data-ira-review-disp-input="keep_pending"
                                disabled>
                            <option value="">Reason required…</option>
                            @foreach($keepPendingReasons as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                @endunless
            </div>
        </section>
    </div>

    <div class="ira-lc-review__footer">
        <p class="ira-lc-review__hint">
            One Save teaches IRA only when Owner / Classification / Importance change, then applies disposition.
        </p>
        <button type="submit" class="btn btn-sm btn-dark" data-ira-review-save>
            Save
        </button>
    </div>
</form>
@endif
