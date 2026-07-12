@php
    use App\Support\Customer360\Customer360OverflowMenuLucideIcon;

    $itemType = $item['type'] ?? 'link';
    $itemClasses = ['c360-quick-toolbar-more-item'];

    if ($item['destructive'] ?? false) {
        $itemClasses[] = 'c360-quick-toolbar-more-item--destructive';
    }

    if (($item['accent'] ?? '') === 'warning') {
        $itemClasses[] = 'c360-quick-toolbar-more-item--warning';
    }
@endphp

@if($itemType === 'trigger')
    <button type="button"
            @class($itemClasses)
            role="menuitem"
            data-workspace-trigger="{{ $item['trigger'] }}"
            data-workspace-incident-id="{{ $incident->id }}"
            data-workspace-context="customer"
            @if(filled($item['workspaceActionType'] ?? null)) data-workspace-action-type="{{ $item['workspaceActionType'] }}" @endif
            @if(filled($item['shortcut'] ?? null)) data-c360-shortcut-action="{{ $item['shortcut'] }}" @endif>
        <span class="c360-quick-toolbar-more-item-main">
            {!! Customer360OverflowMenuLucideIcon::render($item['icon']) !!}
            <span>{{ $item['label'] }}</span>
        </span>
    </button>
@elseif($itemType === 'communication')
    <button type="button"
            @class($itemClasses)
            role="menuitem"
            data-workspace-trigger="communication-action"
            data-workspace-communication-action-key="{{ $item['communicationActionKey'] }}"
            data-workspace-incident-id="{{ $incident->id }}"
            data-workspace-context="customer">
        <span class="c360-quick-toolbar-more-item-main">
            {!! Customer360OverflowMenuLucideIcon::render($item['icon']) !!}
            <span>{{ $item['label'] }}</span>
        </span>
    </button>
@elseif($itemType === 'tab')
    <button type="button"
            @class($itemClasses)
            role="menuitem"
            data-c360-overflow-tab="{{ $item['tab'] }}"
            @if(filled($item['anchor'] ?? null)) data-c360-overflow-anchor="{{ $item['anchor'] }}" @endif>
        <span class="c360-quick-toolbar-more-item-main">
            {!! Customer360OverflowMenuLucideIcon::render($item['icon']) !!}
            <span>{{ $item['label'] }}</span>
        </span>
    </button>
@elseif($itemType === 'status')
    <span @class([...$itemClasses, 'c360-quick-toolbar-more-item--status'])
          role="menuitem"
          aria-disabled="true">
        <span class="c360-quick-toolbar-more-item-main">
            {!! Customer360OverflowMenuLucideIcon::render($item['icon']) !!}
            <span>{{ $item['label'] }}</span>
        </span>
    </span>
@else
    <a href="{{ $item['href'] }}"
       @class($itemClasses)
       role="menuitem"
       @if(str_starts_with((string) ($item['href'] ?? ''), 'http')) target="_blank" rel="noopener noreferrer" @endif>
        <span class="c360-quick-toolbar-more-item-main">
            {!! Customer360OverflowMenuLucideIcon::render($item['icon']) !!}
            <span>{{ $item['label'] }}</span>
        </span>
    </a>
@endif
