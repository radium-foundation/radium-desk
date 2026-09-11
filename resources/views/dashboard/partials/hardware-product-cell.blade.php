@php
    $missing = $row->productMissing;
    $details = $row->productDetails();
    $first = $details[0] ?? null;
    $primary = $first['primary'] ?? $row->productDisplay();
    $secondary = $first['secondary'] ?? null;
    $productTitle = collect($details)->map(function (array $line): string {
        return $line['title'] ?? $line['label'];
    })->implode(' · ');
@endphp

@if($missing)
    <div class="dashboard-hardware-product dashboard-hardware-product--missing" title="{{ $productTitle }}">
        <span class="dashboard-hardware-product__primary">{{ $row->productDisplay() }}</span>
        <div class="dashboard-hardware-product__secondary text-muted small">{{ $row->productExceptionAction() ?? 'View' }}</div>
    </div>
@elseif($row->productHasMore())
    <button type="button"
            class="dashboard-hardware-product dashboard-hardware-product--more"
            data-hardware-product-detail
            aria-expanded="false"
            aria-label="Show all products for {{ $row->sourceId }}"
            title="{{ $productTitle }}">
        <span class="dashboard-hardware-product__primary">{{ $primary }}</span>
        @if($secondary)
            <span class="dashboard-hardware-product__secondary text-muted small">{{ $secondary }}</span>
        @endif
    </button>
    <div class="dashboard-hardware-product-popover" hidden>
        <p class="dashboard-hardware-product-popover__title">Products</p>
        <ul class="dashboard-hardware-product-popover__list">
            @foreach($details as $line)
                <li>
                    <div>{{ $line['primary'] ?? $line['label'] }}</div>
                    @if(! empty($line['secondary']))
                        <div class="text-muted small">{{ $line['secondary'] }}</div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@else
    <div class="dashboard-hardware-product @if($first && ($first['ambiguous'] ?? false)) dashboard-hardware-product--ambiguous @endif"
         title="{{ $productTitle }}">
        <span class="dashboard-hardware-product__primary">{{ $primary }}</span>
        @if($secondary)
            <span class="dashboard-hardware-product__secondary text-muted small">{{ $secondary }}</span>
        @endif
    </div>
@endif
