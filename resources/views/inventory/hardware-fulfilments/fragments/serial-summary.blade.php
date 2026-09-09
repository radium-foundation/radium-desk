@php
    $serials = \App\Support\HardwareFulfilment\HardwareAllocatedSerialDisplay::normalize($serials ?? []);
    $expected = array_key_exists('expected', get_defined_vars()) && $expected !== null ? (int) $expected : null;
    $count = count($serials);
    $compact = $compact ?? \App\Support\HardwareFulfilment\HardwareAllocatedSerialDisplay::compact($serials, $expected);
    $mismatch = $expected !== null && $expected > 0 && $count !== $expected;
    $expandable = $count > 1 || $mismatch;
    $id = $id ?? 'hardware-serial-summary-'.str_replace('.', '', uniqid('', true));
    $wrapperClass = $wrapperClass ?? '';
@endphp

@if($count === 0)
    <span class="{{ $wrapperClass }}">{{ $compact }}</span>
@elseif(! $expandable)
    <x-copyable-identifier
        :value="$serials[0]"
        class="hardware-serial-summary__single font-monospace {{ $wrapperClass }}" />
@else
    <div class="hardware-serial-summary {{ $wrapperClass }}"
         data-hardware-serial-summary
         data-hardware-serial-id="{{ $id }}">
        <button type="button"
                class="hardware-serial-summary__toggle font-monospace @if($mismatch) is-mismatch @endif"
                id="{{ $id }}-toggle"
                data-hardware-serial-toggle
                aria-expanded="false"
                aria-controls="{{ $id }}-panel"
                aria-haspopup="true"
                title="Show all allocated serials">
            {{ $compact }}
        </button>
        <div class="hardware-serial-summary__panel"
             id="{{ $id }}-panel"
             data-hardware-serial-panel
             hidden
             role="region"
             aria-labelledby="{{ $id }}-title">
            <div class="hardware-serial-summary__header">
                <p class="hardware-serial-summary__title mb-0" id="{{ $id }}-title">
                    Allocated Serials ({{ $count }})
                </p>
                <button type="button"
                        class="btn btn-sm btn-link hardware-serial-summary__copy p-0"
                        data-copyable-identifier="serials"
                        data-copy-value="{{ \App\Support\HardwareFulfilment\HardwareAllocatedSerialDisplay::copyValue($serials) }}"
                        data-copy-toast="{{ \App\Support\HardwareFulfilment\HardwareAllocatedSerialDisplay::copyToast($serials) }}">
                    Copy All
                </button>
            </div>
            @if($mismatch)
                <p class="hardware-serial-summary__mismatch mb-2">
                    Serials: {{ $count }} / {{ $expected }} allocated
                </p>
            @elseif($expected !== null)
                <p class="hardware-serial-summary__match mb-2">
                    Quantity {{ $expected }} · {{ $count }} allocated
                </p>
            @endif
            <ol class="hardware-serial-summary__list">
                @foreach($serials as $serial)
                    <li class="font-monospace user-select-all">{{ $serial }}</li>
                @endforeach
            </ol>
        </div>
    </div>
@endif
