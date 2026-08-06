@php
    $isCompletedAutomatically = !empty($card['is_completed_automatically']);
    $isSpamQueue = !empty($card['is_spam_queue']);
    $expandJson = json_encode(
        $card['expand'] ?? [],
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE
    );
@endphp
<div class="ira-lc-row"
     data-ira-row
     data-message-id="{{ $card['id'] }}"
     data-suggested-assignee="{{ $card['suggested_assignee_user_id'] ?? '' }}"
     data-subject="{{ e($card['subject'] ?? 'No subject') }}"
     data-preview="{{ e($card['preview_full'] ?? $card['preview'] ?? '') }}"
     @if($isSpamQueue) data-spam-queue="1" @endif
     @if(!empty($card['keep_pending'])) data-keep-pending="1" @endif>
    <div class="ira-lc-row__main" data-ira-row-toggle role="button" tabindex="0" aria-expanded="false">
        <div class="ira-lc-row__check" data-ira-stop>
            <input type="checkbox"
                   class="form-check-input m-0"
                   value="{{ $card['id'] }}"
                   aria-label="Select email {{ $card['id'] }}"
                   data-ira-row-select>
        </div>

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
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-row-action="assign">Teach Owner</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-row-action="classification">Teach Classification</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-row-action="importance">Teach Importance</button>
                    </li>
                    <li role="separator"><hr class="dropdown-divider"></li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-disp-action="create_case">Create Service Case</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-disp-action="link_case">Link Existing Case</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-disp-action="ignore">Ignore</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-disp-action="spam">Spam</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-disp-action="promotion">Promotion</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-disp-action="auto_processed">Completed Automatically</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-disp-action="keep_pending">Keep Pending</button>
                    </li>
                    <li role="separator"><hr class="dropdown-divider"></li>
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
                </ul>
            </div>
        </div>
    </div>

    <div class="ira-lc-row__expand"
         data-ira-expand
         hidden
         data-expand-json="{{ $expandJson ?: '{}' }}"></div>
</div>
