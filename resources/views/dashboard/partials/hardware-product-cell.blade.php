@php
    $missing = $row->productMissing;
    $details = $row->productDetails();
    $productTitle = $row->productHasMore()
        ? collect($details)->map(function (array $line): string {
            $text = $line['label'];
            if ($line['qty'] !== null) {
                $text .= ' · Qty '.$line['qty'];
            }

            return $text;
        })->implode(' · ')
        : $row->productDisplay();
@endphp

@if($missing)
    <div class="dashboard-hardware-product dashboard-hardware-product--missing" title="{{ $productTitle }}">
        <span>{{ $row->productDisplay() }}</span>
        <div class="text-muted small">{{ $row->productExceptionAction() ?? 'View' }}</div>
    </div>
@elseif($row->productHasMore())
    <button type="button"
            class="dashboard-hardware-product dashboard-hardware-product--more"
            data-hardware-product-detail
            aria-expanded="false"
            aria-label="Show all products for {{ $row->sourceId }}"
            title="{{ $productTitle }}">
        <span>{{ $row->productDisplay() }}</span>
    </button>
    <div class="dashboard-hardware-product-popover" hidden>
        <p class="dashboard-hardware-product-popover__title">Products</p>
        <ul class="dashboard-hardware-product-popover__list">
            @foreach($details as $line)
                <li>
                    {{ $line['label'] }}
                    @if($line['qty'] !== null)
                        — Qty {{ $line['qty'] }}
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@else
    <span class="dashboard-hardware-product" title="{{ $productTitle }}">{{ $row->productDisplay() }}</span>
@endif
