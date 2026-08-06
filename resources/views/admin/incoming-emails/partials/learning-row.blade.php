@php
    $isCompletedAutomatically = !empty($card['is_completed_automatically']);
    $isSpamQueue = !empty($card['is_spam_queue']);
    $supportsReview = in_array($queue, [
        \App\Enums\IncomingEmailIntakeQueue::NeedsHuman,
        \App\Enums\IncomingEmailIntakeQueue::ReviewSuggested,
        \App\Enums\IncomingEmailIntakeQueue::Spam,
    ], true);
    $ownerId = $card['learning_owner_user_id'] ?? $card['suggested_assignee_user_id'] ?? '';
    $expandJson = json_encode(
        $card['expand'] ?? [],
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE
    );
@endphp
<div class="ira-lc-row"
     data-ira-row
     data-message-id="{{ $card['id'] }}"
     data-suggested-assignee="{{ $card['suggested_assignee_user_id'] ?? '' }}"
     data-owner-id="{{ $ownerId }}"
     data-classification="{{ $card['classification_value'] ?? '' }}"
     data-importance="{{ $card['importance_value'] ?? 'normal' }}"
     data-sender="{{ e($card['sender'] ?? '') }}"
     data-sender-email="{{ e($card['sender_email'] ?? '') }}"
     data-subject="{{ e($card['subject'] ?? 'No subject') }}"
     data-preview="{{ e($card['preview_full'] ?? $card['preview'] ?? '') }}"
     @if($supportsReview) data-ira-reviewable="1" @endif
     @if($isSpamQueue) data-spam-queue="1" @endif
     @if(!empty($card['keep_pending'])) data-keep-pending="1" @endif>
    <div class="ira-lc-row__main"
         @if($supportsReview) data-ira-row-select-trigger role="button" tabindex="0" aria-pressed="false" @else data-ira-row-toggle role="button" tabindex="0" aria-expanded="false" @endif>
        @unless($supportsReview)
            <div class="ira-lc-row__check" aria-hidden="true"></div>
        @else
            <div class="ira-lc-row__check ira-lc-row__check--select" aria-hidden="true">
                <span class="ira-lc-row__select-dot"></span>
            </div>
        @endunless

        <div class="ira-lc-row__sender" title="{{ $card['sender_email'] }}">
            <span class="ira-lc-row__primary">{{ $card['sender'] }}</span>
            @if(!empty($card['keep_pending_label']))
                <span class="ira-lc-row__secondary">Pending: {{ $card['keep_pending_label'] }}</span>
            @endif
        </div>

        <div class="ira-lc-row__subject">
            <span class="ira-lc-row__primary">{{ $card['subject'] }}</span>
            <span class="ira-lc-row__secondary">{{ $card['preview'] }}</span>
        </div>

        <div class="ira-lc-row__suggestion" title="{{ $isCompletedAutomatically ? ($card['result_label'] ?? '') : ($card['reason'] ?? '') }}">
            <span class="ira-lc-row__primary">{{ $card['ira_decision'] }}</span>
            @if($isCompletedAutomatically && !empty($card['automatic_subcategory_label']))
                <span class="ira-lc-row__secondary">{{ $card['automatic_subcategory_label'] }}</span>
            @endif
        </div>

        @if($isCompletedAutomatically)
            <div class="ira-lc-row__confidence ira-lc-row__handled" title="Handled by IRA">
                IRA
            </div>
            <div class="ira-lc-row__owner" aria-hidden="true"></div>
        @else
            <div class="ira-lc-row__confidence"
                 title="{{ $card['confidence_percent'] }}">
                <span class="ira-lc-conf ira-lc-conf--{{ strtolower($card['confidence_band']) }}">
                    {{ $card['confidence_band'] }}
                </span>
            </div>

            <div class="ira-lc-row__owner" title="{{ $card['suggested_assignee'] }}">
                {{ $card['suggested_assignee'] }}
            </div>
        @endif

        <div class="ira-lc-row__received" title="{{ $card['received_label'] }}">
            {{ $card['received_label'] }}
        </div>

        <div class="ira-lc-row__actions" data-ira-stop>
            <div class="ira-lc-menu-wrap">
                <button type="button"
                        class="btn btn-sm ira-lc-menu-btn"
                        data-ira-menu-trigger
                        aria-haspopup="menu"
                        aria-expanded="false"
                        aria-label="Row actions">
                    ⋯
                </button>
                <ul class="ira-lc-menu" data-ira-menu role="menu" hidden>
                    @if($supportsReview && !empty($canManageEmailIntake))
                        <li role="none">
                            <button type="button" class="dropdown-item" role="menuitem" data-ira-open-review>
                                Review
                            </button>
                        </li>
                        <li role="separator"><hr class="dropdown-divider"></li>
                    @endif
                    @if($card['gmail_url'])
                        <li role="none">
                            <a class="dropdown-item" role="menuitem" href="{{ $card['gmail_url'] }}" target="_blank" rel="noopener">
                                Open Gmail
                            </a>
                        </li>
                    @else
                        <li role="none"><span class="dropdown-item disabled" role="menuitem" aria-disabled="true">Open Gmail</span></li>
                    @endif
                    @if($card['customer_360_url'])
                        <li role="none">
                            <a class="dropdown-item" role="menuitem" href="{{ $card['customer_360_url'] }}" target="_blank" rel="noopener">
                                Open Customer360
                            </a>
                        </li>
                    @else
                        <li role="none"><span class="dropdown-item disabled" role="menuitem" aria-disabled="true">Open Customer360</span></li>
                    @endif
                    @unless($supportsReview)
                        <li role="separator"><hr class="dropdown-divider"></li>
                        <li role="none">
                            <button type="button" class="dropdown-item" role="menuitem" data-ira-row-toggle-menu>
                                Toggle details
                            </button>
                        </li>
                    @endunless
                </ul>
            </div>
        </div>
    </div>

    <div class="ira-lc-row__expand"
         data-ira-expand
         hidden
         data-expand-json="{{ $expandJson ?: '{}' }}"></div>
</div>
