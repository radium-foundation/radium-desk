@props([
    'customerLabel' => 'Customer',
    'ownerLabel' => 'Unassigned',
    'lastInboundLabel' => '—',
    'lastOutboundLabel' => '—',
    'channelLabel' => null,
])

<div {{ $attributes->merge(['class' => 'c360-channel-meta-header']) }}>
    @if(filled($channelLabel))
        <div class="c360-channel-meta-title">{{ $channelLabel }}</div>
    @endif
    <div class="c360-channel-meta-grid" role="list">
        <div class="c360-channel-meta-item" role="listitem">
            <span class="c360-channel-meta-key">Customer</span>
            <span class="c360-channel-meta-value">{{ $customerLabel }}</span>
        </div>
        <div class="c360-channel-meta-item" role="listitem">
            <span class="c360-channel-meta-key">Owner</span>
            <span class="c360-channel-meta-value">{{ $ownerLabel }}</span>
        </div>
        <div class="c360-channel-meta-item" role="listitem">
            <span class="c360-channel-meta-key">Last inbound</span>
            <span class="c360-channel-meta-value">{{ $lastInboundLabel }}</span>
        </div>
        <div class="c360-channel-meta-item" role="listitem">
            <span class="c360-channel-meta-key">Last outbound</span>
            <span class="c360-channel-meta-value">{{ $lastOutboundLabel }}</span>
        </div>
    </div>
</div>
