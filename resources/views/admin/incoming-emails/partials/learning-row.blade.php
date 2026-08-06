<div class="ira-lc-row"
     data-ira-row
     data-message-id="{{ $card['id'] }}"
     data-suggested-assignee="{{ $card['suggested_assignee_user_id'] ?? '' }}">
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
        </div>

        <div class="ira-lc-row__subject">
            <span class="ira-lc-row__primary">{{ $card['subject'] }}</span>
            <span class="ira-lc-row__secondary">{{ $card['preview'] }}</span>
        </div>

        <div class="ira-lc-row__suggestion" title="{{ $card['reason'] }}">
            {{ $card['ira_decision'] }}
        </div>

        <div class="ira-lc-row__confidence"
             title="{{ $card['confidence_percent'] }}">
            <span class="ira-lc-conf ira-lc-conf--{{ strtolower($card['confidence_band']) }}">
                {{ $card['confidence_band'] }}
            </span>
        </div>

        <div class="ira-lc-row__owner" title="{{ $card['suggested_assignee'] }}">
            {{ $card['suggested_assignee'] }}
        </div>

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
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-row-action="assign">Assign</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-row-action="move">Move</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-row-action="ignore">Ignore</button>
                    </li>
                    <li role="none">
                        <button type="button" class="dropdown-item" role="menuitem" data-ira-row-action="importance">Mark Important</button>
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
         data-expand-json="{{ e(json_encode($card['expand'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) }}"></div>
</div>
