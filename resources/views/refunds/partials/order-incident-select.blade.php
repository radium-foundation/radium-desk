@props([
    'selectedOrder' => null,
    'selectedIncident' => null,
    'orderIdValue' => old('order_id', $selectedOrder?->id),
    'incidentIdValue' => old('incident_id', $selectedIncident?->id),
])

@php
    $orderDisplayValue = old('order_search', $selectedOrder
        ? $selectedOrder->order_id.' — '.$selectedOrder->serial_number
        : '');

    $incidentDisplayValue = old('incident_search', $selectedIncident
        ? $selectedIncident->reference_no.' — '.$selectedIncident->title
        : '');
@endphp

<div class="col-12">
    <label for="order_search" class="form-label">Order <span class="text-danger">*</span></label>
    <input type="hidden" name="order_id" id="order_id" value="{{ $orderIdValue }}">
    <div class="position-relative">
        <input type="text"
               id="order_search"
               class="form-control @error('order_id') is-invalid @enderror"
               value="{{ $orderDisplayValue }}"
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

<div class="col-12">
    <label for="incident_search" class="form-label">Incident <span class="text-muted">(optional)</span></label>
    <input type="hidden" name="incident_id" id="incident_id" value="{{ $incidentIdValue }}">
    <div class="position-relative">
        <input type="text"
               id="incident_search"
               class="form-control @error('incident_id') is-invalid @enderror"
               value="{{ $incidentDisplayValue }}"
               placeholder="Select an order first, then search incidents..."
               autocomplete="off"
               data-lookup-url="{{ route('refunds.incidents.lookup') }}"
               @disabled(! $orderIdValue)>
        <div id="incident_search_results" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index: 1050; max-height: 240px; overflow-y: auto;"></div>
    </div>
    @error('incident_id')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
    <div id="incident_selected_summary" class="form-text mt-1">
        @if($selectedIncident)
            Selected: <strong>{{ $selectedIncident->reference_no }}</strong> — {{ $selectedIncident->title }}
        @endif
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const orderSearchInput = document.getElementById('order_search');
                const orderHiddenInput = document.getElementById('order_id');
                const orderResultsBox = document.getElementById('order_search_results');
                const orderSummary = document.getElementById('order_selected_summary');

                const incidentSearchInput = document.getElementById('incident_search');
                const incidentHiddenInput = document.getElementById('incident_id');
                const incidentResultsBox = document.getElementById('incident_search_results');
                const incidentSummary = document.getElementById('incident_selected_summary');

                if (!orderSearchInput || !orderHiddenInput || !orderResultsBox) {
                    return;
                }

                let orderDebounceTimer = null;
                let incidentDebounceTimer = null;

                const clearIncidentSelection = () => {
                    if (!incidentHiddenInput || !incidentSearchInput) {
                        return;
                    }

                    incidentHiddenInput.value = '';
                    incidentSearchInput.value = '';
                    if (incidentSummary) {
                        incidentSummary.textContent = '';
                    }
                    if (incidentResultsBox) {
                        incidentResultsBox.classList.add('d-none');
                        incidentResultsBox.innerHTML = '';
                    }
                };

                const setIncidentSearchEnabled = (enabled) => {
                    if (!incidentSearchInput) {
                        return;
                    }

                    incidentSearchInput.disabled = !enabled;

                    if (!enabled) {
                        clearIncidentSelection();
                    }
                };

                const clearOrderSelection = () => {
                    orderHiddenInput.value = '';
                    if (orderSummary) {
                        orderSummary.textContent = '';
                    }
                    setIncidentSearchEnabled(false);
                };

                const selectOrder = (order) => {
                    orderHiddenInput.value = order.id;
                    orderSearchInput.value = `${order.order_id} — ${order.serial_number}`;
                    if (orderSummary) {
                        orderSummary.innerHTML = `Selected: <strong>${order.order_id}</strong> — ${order.product_name} (${order.device_model})`;
                    }
                    orderResultsBox.classList.add('d-none');
                    orderResultsBox.innerHTML = '';
                    clearIncidentSelection();
                    setIncidentSearchEnabled(true);
                };

                const selectIncident = (incident) => {
                    if (!incidentHiddenInput || !incidentSearchInput) {
                        return;
                    }

                    incidentHiddenInput.value = incident.id;
                    incidentSearchInput.value = `${incident.reference_no} — ${incident.title}`;
                    if (incidentSummary) {
                        incidentSummary.innerHTML = `Selected: <strong>${incident.reference_no}</strong> — ${incident.title}`;
                    }
                    incidentResultsBox.classList.add('d-none');
                    incidentResultsBox.innerHTML = '';
                };

                const renderOrderResults = (orders) => {
                    orderResultsBox.innerHTML = '';

                    if (!orders.length) {
                        orderResultsBox.innerHTML = '<div class="list-group-item text-muted small">No orders found.</div>';
                        orderResultsBox.classList.remove('d-none');
                        return;
                    }

                    orders.forEach((order) => {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action';
                        item.innerHTML = `<div class="fw-semibold">${order.order_id}</div><div class="small text-muted">${order.serial_number} — ${order.product_name}</div>`;
                        item.addEventListener('click', () => selectOrder(order));
                        orderResultsBox.appendChild(item);
                    });

                    orderResultsBox.classList.remove('d-none');
                };

                const renderIncidentResults = (incidents) => {
                    if (!incidentResultsBox) {
                        return;
                    }

                    incidentResultsBox.innerHTML = '';

                    if (!incidents.length) {
                        incidentResultsBox.innerHTML = '<div class="list-group-item text-muted small">No incidents found for this order.</div>';
                        incidentResultsBox.classList.remove('d-none');
                        return;
                    }

                    incidents.forEach((incident) => {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action';
                        item.innerHTML = `<div class="fw-semibold">${incident.reference_no}</div><div class="small text-muted">${incident.title} · ${incident.status}</div>`;
                        item.addEventListener('click', () => selectIncident(incident));
                        incidentResultsBox.appendChild(item);
                    });

                    incidentResultsBox.classList.remove('d-none');
                };

                orderSearchInput.addEventListener('input', () => {
                    clearOrderSelection();
                    const term = orderSearchInput.value.trim();

                    if (term.length < 2) {
                        orderResultsBox.classList.add('d-none');
                        orderResultsBox.innerHTML = '';
                        return;
                    }

                    clearTimeout(orderDebounceTimer);
                    orderDebounceTimer = setTimeout(async () => {
                        try {
                            const response = await fetch(`${orderSearchInput.dataset.lookupUrl}?q=${encodeURIComponent(term)}`, {
                                headers: { 'Accept': 'application/json' },
                            });

                            if (!response.ok) {
                                return;
                            }

                            renderOrderResults(await response.json());
                        } catch (error) {
                            orderResultsBox.classList.add('d-none');
                        }
                    }, 300);
                });

                incidentSearchInput?.addEventListener('focus', () => {
                    if (!orderHiddenInput.value) {
                        return;
                    }

                    incidentSearchInput.dispatchEvent(new Event('input'));
                });

                incidentSearchInput?.addEventListener('input', () => {
                    if (incidentHiddenInput) {
                        incidentHiddenInput.value = '';
                    }
                    if (incidentSummary) {
                        incidentSummary.textContent = '';
                    }

                    const orderId = orderHiddenInput.value;
                    const term = incidentSearchInput.value.trim();

                    if (!orderId) {
                        return;
                    }

                    clearTimeout(incidentDebounceTimer);
                    incidentDebounceTimer = setTimeout(async () => {
                        try {
                            const url = `${incidentSearchInput.dataset.lookupUrl}?order_id=${encodeURIComponent(orderId)}&q=${encodeURIComponent(term)}`;
                            const response = await fetch(url, {
                                headers: { 'Accept': 'application/json' },
                            });

                            if (!response.ok) {
                                return;
                            }

                            renderIncidentResults(await response.json());
                        } catch (error) {
                            incidentResultsBox?.classList.add('d-none');
                        }
                    }, 300);
                });

                document.addEventListener('click', (event) => {
                    if (!orderSearchInput.contains(event.target) && !orderResultsBox.contains(event.target)) {
                        orderResultsBox.classList.add('d-none');
                    }

                    if (incidentSearchInput && incidentResultsBox
                        && !incidentSearchInput.contains(event.target)
                        && !incidentResultsBox.contains(event.target)) {
                        incidentResultsBox.classList.add('d-none');
                    }
                });

                setIncidentSearchEnabled(Boolean(orderHiddenInput.value));
            });
        </script>
    @endpush
@endonce
