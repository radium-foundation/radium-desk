@foreach($overflowMenuGroups as $groupIndex => $group)
    @if(($group['items'] ?? []) !== [])
        @if($groupIndex > 0)
            <div class="c360-quick-toolbar-more-divider" role="separator" aria-hidden="true"></div>
        @endif
        <div class="c360-quick-toolbar-more-group" role="group" aria-label="{{ $group['label'] }}">
            <p class="c360-quick-toolbar-more-group-label">
                <span aria-hidden="true">{{ $group['icon'] ?? '' }}</span>
                <span>{{ $group['label'] }}</span>
            </p>
            @foreach($group['items'] as $item)
                @if($item['dividerBefore'] ?? false)
                    <div class="c360-quick-toolbar-more-divider c360-quick-toolbar-more-divider--inset" role="separator" aria-hidden="true"></div>
                @endif
                @include('customer-360.partials.overflow-menu-item', [
                    'item' => $item,
                    'incident' => $incident,
                ])
            @endforeach
        </div>
    @endif
@endforeach
