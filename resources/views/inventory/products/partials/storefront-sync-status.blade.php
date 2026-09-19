@php
    use App\Enums\CatalogPriceSyncStatus;
@endphp

@if ($catalogPriceSync === null)
    <div class="alert alert-secondary border-0 shadow-sm">
        No storefront price sync has been attempted for this product yet.
    </div>
@elseif ($catalogPriceSync->status === CatalogPriceSyncStatus::Synced)
    <div class="alert alert-success border-0 shadow-sm">
        Storefront price synced.
        @if ($catalogPriceSync->applied_liveprice !== null)
            Current storefront price: ₹{{ number_format((float) $catalogPriceSync->applied_liveprice, 0) }}.
        @endif
        @if ($catalogPriceSync->applied_at !== null)
            <span class="small text-muted d-block mt-1">Applied {{ $catalogPriceSync->applied_at->timezone(config('app.timezone'))->format('d M Y, H:i') }}.</span>
        @endif
    </div>
@elseif ($catalogPriceSync->status === CatalogPriceSyncStatus::Pending)
    <div class="alert alert-warning border-0 shadow-sm mb-0">
        Storefront price sync is pending.
    </div>
@else
    <div class="alert alert-danger border-0 shadow-sm">
        Storefront price not updated. Last error: {{ $catalogPriceSync->error_summary ?? 'Unknown error' }}.
        <form method="POST" action="{{ route('inventory.products.retry-storefront-sync', $product) }}" class="mt-3">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-danger">Retry storefront sync</button>
        </form>
    </div>
@endif
