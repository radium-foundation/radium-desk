@php
    $lines = collect($requirements ?? []);
    $lineCount = $lines->count();
    $requiredTotal = (int) $lines->sum('qty');
    $compact = $lineCount <= 1 && $requiredTotal <= 1;
    $submitLabel = $compact ? 'Allocate Serial' : 'Allocate Serials';
    $sectionTitle = $compact ? 'Search / select serial' : 'Allocate serials';
    $unavailable = $allocateUnavailableReason ?? null;
@endphp

<form method="POST"
      action="{{ route('inventory.hardware-fulfilments.serials.store', $fulfilment) }}"
      data-hardware-action-form
      id="hardware-action-serial-form">
    @csrf
    <x-c360.section-card :title="$sectionTitle" class="mb-2">
        <div data-hardware-serial-allocate
             data-compact="{{ $compact ? '1' : '0' }}"
             data-required-total="{{ $requiredTotal }}">
            @forelse($lines as $index => $line)
                @php
                    $itemId = (int) $line['commerce_order_item_id'];
                    $qty = (int) $line['qty'];
                    $sku = $line['inventory_sku'] ?? $line['sku'] ?? '';
                    $already = (int) ($line['allocated_qty'] ?? 0);
                    $isLast = $index === $lineCount - 1;
                @endphp
                <div class="{{ $isLast ? 'mb-0' : 'mb-3 pb-3 border-bottom' }}"
                     data-hardware-serial-picker
                     data-item-id="{{ $itemId }}"
                     data-qty="{{ $qty }}"
                     data-search-url="{{ $searchUrl }}"
                     data-product="{{ $line['description'] }}"
                     data-sku="{{ $sku }}">
                    @if(! $compact)
                        <p class="small text-muted text-uppercase fw-semibold mb-1">Product {{ $index + 1 }}</p>
                    @endif
                    <p class="fw-semibold mb-1">{{ $line['description'] }}</p>
                    <p class="small text-muted mb-2">
                        @if($sku !== '')
                            SKU: {{ $sku }}
                        @else
                            SKU unset
                        @endif
                        @if($line['model_id'] ?? null)
                            · model {{ $line['model_id'] }}
                        @endif
                        · Qty {{ $qty }}
                    </p>
                    <p class="small mb-2" data-hardware-serial-count>
                        Serials allocated: {{ $already }} / {{ $qty }}
                    </p>
                    <label class="form-label" for="hardware-action-serial-search-{{ $itemId }}">Search serial</label>
                    <input type="search"
                           id="hardware-action-serial-search-{{ $itemId }}"
                           class="form-control"
                           data-hardware-serial-query
                           placeholder="Serial number"
                           autocomplete="off"
                           @disabled(! ($canAllocate ?? false))>
                    <div class="small mt-2" data-hardware-serial-results aria-live="polite"></div>
                    <p class="small text-muted mb-1 mt-2">Selected serials</p>
                    <ul class="list-unstyled small mb-0" data-hardware-serial-selected></ul>
                    <p class="small text-danger mb-0 mt-2 d-none" data-hardware-serial-line-error></p>
                    @if(! ($line['map_ready'] ?? true))
                        <p class="small text-danger mb-0 mt-2">Owner SKU map is missing for this model_id. Allocation is blocked.</p>
                    @endif
                </div>
            @empty
                <p class="small text-muted mb-0">No allocatable lines on this fulfilment.</p>
            @endforelse

            @if(($canAllocate ?? false) && $lineCount > 0 && ! $compact)
                <div class="mt-3">
                    <p class="small mb-1" data-hardware-serial-branch>Branch: Derived from selected serials</p>
                    <p class="small mb-0" data-hardware-serial-totals>
                        Total required: {{ $requiredTotal }} · Total selected: 0
                    </p>
                </div>
            @endif

            <p class="small text-danger mb-0 mt-2 d-none" data-hardware-serial-client-error role="alert"></p>

            @if(! ($canAllocate ?? false))
                <p class="small text-danger mb-0 mt-2" data-hardware-serial-unavailable>
                    {{ $unavailable ?: 'Serial allocation is not available for this order yet.' }}
                </p>
            @endif
        </div>
    </x-c360.section-card>
    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit"
                class="btn c360-dialog-btn-primary"
                data-hardware-action-submit
                data-hardware-serial-submit-idle="{{ $submitLabel }}"
                disabled>
            {{ $submitLabel }}
        </button>
    </x-c360.modal-footer>
</form>
