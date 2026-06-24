@props([
    'selectedOrder' => null,
    'orderIdValue' => old('order_id', $selectedOrder?->id),
])

@php
    $displayValue = old('order_search', $selectedOrder
        ? $selectedOrder->order_id.' — '.$selectedOrder->serial_number
        : '');
@endphp

<div class="col-12">
    <label for="order_search" class="form-label">Order <span class="text-danger">*</span></label>
    <input type="hidden" name="order_id" id="order_id" value="{{ $orderIdValue }}">
    <div class="position-relative">
        <input type="text"
               id="order_search"
               class="form-control @error('order_id') is-invalid @enderror"
               value="{{ $displayValue }}"
               placeholder="Search by Order ID or Serial Number..."
               autocomplete="off"
               data-lookup-url="{{ route('orders.lookup') }}">
        <div id="order_search_results" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index: 1050; max-height: 240px; overflow-y: auto;"></div>
    </div>
    @error('order_id')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
    <div id="order_selected_summary" class="form-text mt-1">
        @if($selectedOrder)
            Selected: <strong>{{ $selectedOrder->order_id }}</strong> — {{ $selectedOrder->product_name }} ({{ $selectedOrder->device_model }})
        @endif
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const searchInput = document.getElementById('order_search');
                const hiddenInput = document.getElementById('order_id');
                const resultsBox = document.getElementById('order_search_results');
                const summary = document.getElementById('order_selected_summary');

                if (!searchInput || !hiddenInput || !resultsBox) {
                    return;
                }

                let debounceTimer = null;

                const clearSelection = () => {
                    hiddenInput.value = '';
                    if (summary) {
                        summary.textContent = '';
                    }
                };

                const selectOrder = (order) => {
                    hiddenInput.value = order.id;
                    searchInput.value = `${order.order_id} — ${order.serial_number}`;
                    if (summary) {
                        summary.innerHTML = `Selected: <strong>${order.order_id}</strong> — ${order.product_name} (${order.device_model})`;
                    }
                    resultsBox.classList.add('d-none');
                    resultsBox.innerHTML = '';
                };

                const renderResults = (orders) => {
                    resultsBox.innerHTML = '';

                    if (!orders.length) {
                        resultsBox.innerHTML = '<div class="list-group-item text-muted small">No orders found.</div>';
                        resultsBox.classList.remove('d-none');
                        return;
                    }

                    orders.forEach((order) => {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action';
                        item.innerHTML = `<div class="fw-semibold">${order.order_id}</div><div class="small text-muted">${order.serial_number} — ${order.product_name}</div>`;
                        item.addEventListener('click', () => selectOrder(order));
                        resultsBox.appendChild(item);
                    });

                    resultsBox.classList.remove('d-none');
                };

                searchInput.addEventListener('input', () => {
                    clearSelection();
                    const term = searchInput.value.trim();

                    if (term.length < 2) {
                        resultsBox.classList.add('d-none');
                        resultsBox.innerHTML = '';
                        return;
                    }

                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(async () => {
                        try {
                            const response = await fetch(`${searchInput.dataset.lookupUrl}?q=${encodeURIComponent(term)}`, {
                                headers: { 'Accept': 'application/json' },
                            });

                            if (!response.ok) {
                                return;
                            }

                            renderResults(await response.json());
                        } catch (error) {
                            resultsBox.classList.add('d-none');
                        }
                    }, 300);
                });

                document.addEventListener('click', (event) => {
                    if (!searchInput.contains(event.target) && !resultsBox.contains(event.target)) {
                        resultsBox.classList.add('d-none');
                    }
                });
            });
        </script>
    @endpush
@endonce
