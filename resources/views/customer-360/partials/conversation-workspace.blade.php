@php
    $workspace = $conversationWorkspace ?? null;
    $session = is_array($workspace['session'] ?? null) ? $workspace['session'] : [];
    $question = is_array($session['active_question'] ?? null) ? $session['active_question'] : null;
    $progress = is_array($session['progress'] ?? null) ? $session['progress'] : ['done' => 0, 'total' => 0, 'label' => '0 / 0'];
    $checklist = is_array($session['checklist'] ?? null) ? $session['checklist'] : [];
    $captured = is_array($session['captured'] ?? null) ? $session['captured'] : [];
@endphp

<section class="cw-workspace"
         data-conversation-workspace
         data-cw-update-url="{{ $workspace['update_url'] }}"
         data-cw-call-id="{{ $workspace['call_id'] ?? '' }}"
         data-cw-session='@json($session)'
         aria-label="Customer Conversation Workspace">

    <header class="cw-header">
        <div class="cw-header-main">
            <span class="cw-header-call" title="Incoming call">
                <i class="bi bi-telephone-inbound" aria-hidden="true"></i>
                <span class="visually-hidden">Incoming Call</span>
            </span>
            @if(filled($workspace['phone'] ?? null))
                <span class="cw-header-phone font-monospace">{{ $workspace['phone'] }}</span>
            @endif
            <x-c360.chip value="New Customer" variant="info" icon="bi-person-plus" />
            <x-c360.chip value="First Contact" variant="primary" icon="bi-stars" />
            @if(filled($workspace['agent_name'] ?? null))
                <span class="cw-header-agent" title="Assigned agent">
                    <i class="bi bi-headset" aria-hidden="true"></i>
                    <span>{{ $workspace['agent_name'] }}</span>
                </span>
            @endif
            <span class="cw-header-timer font-monospace"
                  data-cw-timer
                  data-cw-timer-started-at="{{ now()->toIso8601String() }}"
                  title="Call timer">00:00</span>
        </div>
        <div class="cw-header-actions">
            @if($workspace['can_link_order'] ?? false)
                <button type="button"
                        class="cw-link-order-btn"
                        data-workspace-trigger="link-order"
                        data-workspace-incident-id="{{ $workspace['incident_id'] }}"
                        data-workspace-context="customer"
                        title="Link Existing Order">
                    <i class="bi bi-link-45deg" aria-hidden="true"></i>
                    <span>Link Order</span>
                </button>
            @endif
        </div>
    </header>

    <p class="cw-ira-tip" data-cw-ira-tip>
        <i class="bi bi-stars" aria-hidden="true"></i>
        <span>{{ $session['ira_tip'] ?? 'First contact. Understand the need. Capture enough so the next agent never starts from zero.' }}</span>
    </p>

    <div class="cw-guide" data-cw-guide>
        @if($question)
            <div class="cw-question" data-cw-question>
                <div class="cw-question-prompt">
                    <span class="cw-question-icon" aria-hidden="true">
                        @switch($question['key'] ?? '')
                            @case('customer_name')
                                <i class="bi bi-person"></i>
                                @break
                            @case('customer_need')
                                <i class="bi bi-chat-dots"></i>
                                @break
                            @case('email')
                                <i class="bi bi-envelope"></i>
                                @break
                            @case('whatsapp')
                                <i class="bi bi-whatsapp"></i>
                                @break
                            @case('agent_notes')
                                <i class="bi bi-journal-text"></i>
                                @break
                            @case('disposition')
                                <i class="bi bi-flag"></i>
                                @break
                            @case('next_action')
                                <i class="bi bi-arrow-right-circle"></i>
                                @break
                            @default
                                <i class="bi bi-question-circle"></i>
                        @endswitch
                    </span>
                    <strong data-cw-prompt>{{ $question['prompt'] }}</strong>
                    @if(!empty($question['required']))
                        <span class="cw-required" aria-label="Required">*</span>
                    @endif
                </div>

                @if(filled($question['hint'] ?? null))
                    <p class="cw-question-hint">{{ $question['hint'] }}</p>
                @endif

                <div class="cw-question-input" data-cw-input-host>
                    @if(($question['input_type'] ?? 'text') === 'textarea')
                        <textarea class="form-control form-control-sm cw-input"
                                  data-cw-field="{{ $question['key'] }}"
                                  rows="2"
                                  placeholder="{{ $question['prompt'] }}">{{ $captured[$question['key']] ?? '' }}</textarea>
                    @elseif(($question['input_type'] ?? '') === 'select')
                        <select class="form-select form-select-sm cw-input" data-cw-field="{{ $question['key'] }}">
                            <option value="">Select…</option>
                            @foreach(($question['options'] ?? []) as $option)
                                <option value="{{ $option['value'] }}" @selected(($captured[$question['key']] ?? null) === $option['value'])>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                    @elseif(($question['input_type'] ?? '') === 'choice')
                        <div class="cw-choice-row" data-cw-field="{{ $question['key'] }}">
                            @foreach(($question['options'] ?? []) as $option)
                                <button type="button"
                                        class="cw-choice-btn"
                                        data-cw-choice="{{ $option['value'] }}">
                                    {{ $option['label'] }}
                                </button>
                            @endforeach
                        </div>
                    @else
                        <input type="{{ $question['input_type'] === 'email' ? 'email' : 'text' }}"
                               class="form-control form-control-sm cw-input"
                               data-cw-field="{{ $question['key'] }}"
                               value="{{ $captured[$question['key']] ?? ($question['key'] === 'order_id' ? ($captured['order_id'] ?? '') : '') }}"
                               placeholder="{{ $question['prompt'] }}"
                               autocomplete="off" />
                    @endif
                </div>

                <div class="cw-question-actions">
                    <button type="button" class="btn btn-sm btn-primary cw-save-btn" data-cw-save>
                        Continue
                    </button>
                    @if(!empty($question['skippable']))
                        <button type="button" class="btn btn-sm btn-link cw-skip-btn" data-cw-skip>
                            Skip
                        </button>
                    @endif
                </div>
            </div>
        @else
            <div class="cw-question cw-question--done" data-cw-question-done>
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <span>Ready. Keep talking — capture more anytime below.</span>
            </div>
        @endif
    </div>

    <details class="cw-checklist">
        <summary>
            <span class="cw-progress" data-cw-progress>{{ $progress['label'] ?? '0 / 0' }}</span>
            <span class="cw-checklist-label">Checklist</span>
        </summary>
        <ul class="cw-checklist-list">
            @foreach($checklist as $key => $done)
                <li @class(['is-done' => $done])>
                    <i class="bi {{ $done ? 'bi-check-circle-fill' : 'bi-circle' }}" aria-hidden="true"></i>
                    <span>{{ str($key)->replace('_', ' ')->title() }}</span>
                </li>
            @endforeach
        </ul>
    </details>

    <details class="cw-more">
        <summary>More Details</summary>
        <div class="cw-more-grid">
            <label>
                <span>City</span>
                <input type="text" class="form-control form-control-sm" data-cw-more="city" value="{{ $captured['city'] ?? '' }}" />
            </label>
            <label>
                <span>Brand</span>
                <input type="text" class="form-control form-control-sm" data-cw-more="brand" value="{{ $captured['brand'] ?? '' }}" />
            </label>
            <label>
                <span>Model</span>
                <input type="text" class="form-control form-control-sm" data-cw-more="model" value="{{ $captured['model'] ?? '' }}" />
            </label>
            <label>
                <span>Source</span>
                <input type="text" class="form-control form-control-sm" data-cw-more="source" value="{{ $captured['source'] ?? '' }}" />
            </label>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-cw-save-more>Save</button>
        </div>
    </details>
</section>
