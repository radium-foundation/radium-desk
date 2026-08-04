@php
    $section = $communicationSection ?? null;
    $channels = is_array($section['channels'] ?? null) ? $section['channels'] : [];
    $future = is_array($section['future_channels'] ?? null) ? $section['future_channels'] : [];
@endphp

@if($channels !== [])
<section class="c360-communication-section"
         data-customer-360-section="communication"
         data-c360-communication-section
         aria-labelledby="c360-communication-heading">
    <div class="c360-communication-section-head">
        <h3 class="customer-360-section-title mb-0" id="c360-communication-heading">Communication</h3>
        <p class="small text-muted mb-0">WhatsApp and Email on this Service Case. Timeline stays the single chronological record.</p>
    </div>

    <div class="c360-communication-channel-grid" role="list">
        @foreach($channels as $channel)
            @php
                $available = (bool) ($channel['available'] ?? false);
                $key = (string) ($channel['key'] ?? '');
            @endphp
            <article class="c360-communication-channel-card"
                     role="listitem"
                     data-c360-channel-card="{{ $key }}">
                <div class="c360-communication-channel-card-top">
                    <div class="c360-communication-channel-title">
                        <i class="bi {{ $channel['icon'] ?? 'bi-chat' }}" aria-hidden="true"></i>
                        <span>{{ $channel['label'] ?? 'Channel' }}</span>
                    </div>
                    <span class="c360-communication-channel-status">{{ $channel['status_label'] ?? '' }}</span>
                </div>

                <x-c360.channel-meta-header
                    :customer-label="$channel['customer_label'] ?? ($section['customer_label'] ?? 'Customer')"
                    :owner-label="$channel['owner_label'] ?? ($section['owner_label'] ?? 'Unassigned')"
                    :last-inbound-label="$channel['last_inbound_label'] ?? '—'"
                    :last-outbound-label="$channel['last_outbound_label'] ?? '—'"
                />

                <div class="c360-communication-channel-actions">
                    @if($key === 'email')
                        <button type="button"
                                class="btn btn-sm btn-primary"
                                data-c360-email-open
                                data-c360-email-thread-url="{{ $channel['thread_url'] ?? '' }}"
                                data-c360-email-read-url="{{ $channel['read_url'] ?? '' }}">
                            Open Email
                            @if(((int) ($channel['unread_count'] ?? 0)) > 0)
                                <span class="c360-email-unread-badge" data-c360-email-unread-badge>{{ min(9, (int) $channel['unread_count']) }}{{ ((int) $channel['unread_count']) > 9 ? '+' : '' }}</span>
                            @endif
                        </button>
                    @elseif($key === 'whatsapp')
                        <button type="button"
                                class="btn btn-sm btn-primary"
                                @disabled(! $available)
                                data-c360-whatsapp-open
                                data-c360-whatsapp-customer="{{ $channel['customer_label'] ?? '' }}"
                                data-c360-whatsapp-owner="{{ $channel['owner_label'] ?? '' }}"
                                data-c360-whatsapp-last-in="{{ $channel['last_inbound_label'] ?? '—' }}"
                                data-c360-whatsapp-last-out="{{ $channel['last_outbound_label'] ?? '—' }}"
                                data-c360-whatsapp-wa-url="{{ $channel['wa_me_url'] ?? '' }}"
                                data-c360-whatsapp-interakt-url="{{ $channel['interakt_url'] ?? '' }}">
                            Open WhatsApp
                        </button>
                    @endif

                    @if(! $available && filled($channel['unavailable_reason'] ?? null))
                        <p class="small text-muted mb-0">{{ $channel['unavailable_reason'] }}</p>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    @if($future !== [])
        <div class="c360-communication-future" aria-label="Future channels">
            @foreach($future as $item)
                <span class="c360-communication-future-chip" title="{{ $item['status'] ?? 'Coming later' }}">
                    {{ $item['label'] ?? '' }}
                </span>
            @endforeach
        </div>
    @endif
</section>
@endif
