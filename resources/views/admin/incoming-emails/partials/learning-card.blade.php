<article class="ira-learning-card" data-ira-card data-message-id="{{ $card['id'] }}">
    <div class="ira-learning-card__select">
        <input type="checkbox"
               class="form-check-input"
               value="{{ $card['id'] }}"
               aria-label="Select email {{ $card['id'] }}"
               data-ira-card-select>
    </div>

    <div class="ira-learning-card__body">
        <div class="ira-learning-card__meta">
            <div>
                <div class="ira-learning-card__label">Sender</div>
                <div class="ira-learning-card__value">{{ $card['sender'] }}</div>
                @if($card['sender_email'] && $card['sender'] !== $card['sender_email'])
                    <div class="ira-learning-card__sub">{{ $card['sender_email'] }}</div>
                @endif
            </div>
            <div>
                <div class="ira-learning-card__label">Customer</div>
                <div class="ira-learning-card__value">{{ $card['customer'] }}</div>
            </div>
            <div>
                <div class="ira-learning-card__label">Received</div>
                <div class="ira-learning-card__value ira-learning-card__value--muted">
                    {{ display_app_datetime($card['received_at']) }}
                </div>
            </div>
        </div>

        <div class="ira-learning-card__subject">{{ $card['subject'] }}</div>
        <p class="ira-learning-card__preview">{{ $card['preview'] }}</p>

        <div class="ira-learning-card__decision-row">
            <div>
                <div class="ira-learning-card__label">IRA Decision</div>
                <div class="ira-learning-card__decision">{{ $card['ira_decision'] }}</div>
            </div>
            <div>
                <div class="ira-learning-card__label">Confidence</div>
                <div class="ira-learning-card__confidence">{{ $card['confidence_label'] }}</div>
            </div>
            <div>
                <div class="ira-learning-card__label">Suggested Assignee</div>
                <div class="ira-learning-card__value">{{ $card['suggested_assignee'] }}</div>
            </div>
            <div>
                <div class="ira-learning-card__label">Importance</div>
                <div class="ira-learning-card__value">{{ $card['importance'] }}</div>
            </div>
        </div>

        <div class="ira-learning-card__reason">
            <span class="ira-learning-card__label">Reason</span>
            <span>{{ $card['reason'] }}</span>
        </div>

        <details class="ira-learning-card__explain">
            <summary>Why this suggestion?</summary>
            <dl class="ira-learning-card__explain-dl">
                <div>
                    <dt>Why</dt>
                    <dd>{{ $card['explanation']['why'] }}</dd>
                </div>
                @if(($card['explanation']['examples'] ?? []) !== [])
                    <div>
                        <dt>Examples</dt>
                        <dd>
                            <ul class="mb-0 ps-3">
                                @foreach($card['explanation']['examples'] as $example)
                                    <li>{{ $example }}</li>
                                @endforeach
                            </ul>
                        </dd>
                    </div>
                @endif
                @if($card['explanation']['matched_sender'] ?? null)
                    <div>
                        <dt>Matched sender</dt>
                        <dd>{{ $card['explanation']['matched_sender'] }}</dd>
                    </div>
                @endif
                @if($card['explanation']['matched_keyword'] ?? null)
                    <div>
                        <dt>Matched keyword</dt>
                        <dd>{{ $card['explanation']['matched_keyword'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt>Previous operator confirmation</dt>
                    <dd>{{ ($card['explanation']['previous_operator_confirmation'] ?? false) ? 'Yes' : 'No' }}</dd>
                </div>
                <div>
                    <dt>Rule confidence</dt>
                    <dd>
                        @if(($card['explanation']['rule_confidence'] ?? null) !== null)
                            {{ $card['explanation']['rule_confidence'] }}%
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
        </details>

        <div class="ira-learning-card__actions">
            <form method="POST" action="{{ route('admin.incoming-emails.learning.apply') }}" class="ira-learning-card__action-form">
                @csrf
                <input type="hidden" name="message_ids[]" value="{{ $card['id'] }}">
                <input type="hidden" name="action" value="assign">
                <label class="form-label small mb-1">Assign</label>
                <div class="d-flex flex-wrap gap-1">
                    <select name="assignee_user_id" class="form-select form-select-sm" required>
                        <option value="">Choose user</option>
                        @foreach($assignableUsers as $user)
                            <option value="{{ $user->id }}" @selected((int) ($card['suggested_assignee_user_id'] ?? 0) === $user->id)>
                                {{ method_exists($user, 'firstName') ? $user->firstName() : $user->name }}
                            </option>
                        @endforeach
                    </select>
                    <select name="scope" class="form-select form-select-sm" required>
                        @foreach($learningScopes as $scope)
                            <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.incoming-emails.learning.apply') }}" class="ira-learning-card__action-form">
                @csrf
                <input type="hidden" name="message_ids[]" value="{{ $card['id'] }}">
                <input type="hidden" name="action" value="classification">
                <label class="form-label small mb-1">Classification</label>
                <div class="d-flex flex-wrap gap-1">
                    <select name="classification" class="form-select form-select-sm" required>
                        @foreach($operatorClassifications as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    <select name="scope" class="form-select form-select-sm" required>
                        @foreach($learningScopes as $scope)
                            <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.incoming-emails.learning.apply') }}" class="ira-learning-card__action-form">
                @csrf
                <input type="hidden" name="message_ids[]" value="{{ $card['id'] }}">
                <input type="hidden" name="action" value="importance">
                <label class="form-label small mb-1">Importance</label>
                <div class="d-flex flex-wrap gap-1">
                    <select name="importance" class="form-select form-select-sm" required>
                        @foreach($importanceOptions as $option)
                            <option value="{{ $option->value }}" @selected($card['importance_value'] === $option->value)>
                                {{ $option->label() }}
                            </option>
                        @endforeach
                    </select>
                    <select name="scope" class="form-select form-select-sm" required>
                        @foreach($learningScopes as $scope)
                            <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.incoming-emails.learning.apply') }}" class="ira-learning-card__action-form">
                @csrf
                <input type="hidden" name="message_ids[]" value="{{ $card['id'] }}">
                <input type="hidden" name="action" value="ignore">
                <label class="form-label small mb-1">Ignore</label>
                <div class="d-flex flex-wrap gap-1">
                    <select name="ignore_action" class="form-select form-select-sm" required>
                        @foreach($ignoreActions as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    <select name="scope" class="form-select form-select-sm" required>
                        @foreach($learningScopes as $scope)
                            <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-danger">Apply</button>
                </div>
            </form>
        </div>
    </div>
</article>
