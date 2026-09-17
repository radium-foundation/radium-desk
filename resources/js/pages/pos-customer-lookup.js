/**
 * Shared inventory_customers search/select for Product POS and Service POS counters.
 */
export function initPosCustomerLookup(config) {
    const {
        searchUrl,
        showUrlTemplate,
        lookupUrl = null,
        fields,
        resultsEl,
        statusEl,
        searchInputs = [],
        minLength = 2,
        selectedMessage = 'Existing customer selected.',
        noResultsMessage = 'No matching customers found.',
        searchErrorMessage = 'Could not search customers. Try again.',
        loadErrorMessage = 'Could not load that customer. Try again.',
    } = config;

    if (!searchUrl || !showUrlTemplate || !resultsEl || !fields) {
        return;
    }

    let selectedCustomerId = null;
    const timers = new Map();

    function setStatus(message) {
        if (statusEl) {
            statusEl.textContent = message || '';
        }
    }

    function hideResults() {
        resultsEl.innerHTML = '';
        resultsEl.classList.add('d-none');
    }

    function applyCustomerPayload(data) {
        if (!data || !data.found) {
            return;
        }

        selectedCustomerId = data.id || null;

        if (fields.name && data.name) {
            fields.name.value = data.name;
        }
        if (fields.phone && data.phone) {
            fields.phone.value = data.phone;
        }
        if (fields.email) {
            fields.email.value = data.email || '';
        }
        if (fields.gstin) {
            fields.gstin.value = data.gstin || '';
        }
        if (fields.billing_address && data.billing_address) {
            fields.billing_address.value = data.billing_address;
        }
        if (fields.billing_city && data.billing_city) {
            fields.billing_city.value = data.billing_city;
        }
        if (fields.billing_state && data.billing_state) {
            fields.billing_state.value = data.billing_state;
        }
        if (fields.billing_pincode && data.billing_pincode) {
            fields.billing_pincode.value = data.billing_pincode;
        }
        if (fields.place_of_supply_state && data.place_of_supply_state) {
            fields.place_of_supply_state.value = data.place_of_supply_state;
        }

        hideResults();
        setStatus(selectedMessage);
    }

    function showCustomerResults(customers) {
        resultsEl.innerHTML = '';

        if (!customers.length) {
            hideResults();
            selectedCustomerId = null;
            setStatus(noResultsMessage);

            return;
        }

        customers.forEach((customer) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action';
            const emailNote = customer.email ? ` · ${customer.email}` : '';
            const gstinNote = customer.gstin ? ` · GSTIN ${customer.gstin}` : '';
            button.textContent = `${customer.name} · ${customer.phone}${emailNote}${gstinNote}`;
            button.addEventListener('click', () => {
                fetch(showUrlTemplate.replace('__ID__', String(customer.id)), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('customer-show-failed');
                        }

                        return response.json();
                    })
                    .then(applyCustomerPayload)
                    .catch(() => setStatus(loadErrorMessage));
            });
            resultsEl.appendChild(button);
        });

        resultsEl.classList.remove('d-none');
        setStatus('Select a customer below, or continue typing to refine the search.');
    }

    function searchCustomers(query) {
        const trimmed = (query || '').trim();

        if (trimmed.length < minLength) {
            hideResults();
            selectedCustomerId = null;
            setStatus('');

            return;
        }

        fetch(`${searchUrl}?q=${encodeURIComponent(trimmed)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('customer-search-failed');
                }

                return response.json();
            })
            .then((data) => showCustomerResults(data.customers || []))
            .catch(() => setStatus(searchErrorMessage));
    }

    function scheduleSearch(inputEl, query) {
        const existing = timers.get(inputEl);
        if (existing) {
            clearTimeout(existing);
        }

        timers.set(
            inputEl,
            setTimeout(() => searchCustomers(query), 250),
        );
    }

    searchInputs.forEach((inputEl) => {
        if (!inputEl) {
            return;
        }

        inputEl.addEventListener('input', () => {
            scheduleSearch(inputEl, inputEl.value);
        });
    });

    if (lookupUrl && fields.phone) {
        fields.phone.addEventListener('input', () => {
            const phone = fields.phone.value.replace(/\s+/g, '');
            if (phone.length < 10) {
                return;
            }

            fetch(`${lookupUrl}?phone=${encodeURIComponent(phone)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('customer-lookup-failed');
                    }

                    return response.json();
                })
                .then((data) => {
                    if (data.found) {
                        applyCustomerPayload(data);
                    }
                })
                .catch(() => {});
        });
    }

    return {
        getSelectedCustomerId: () => selectedCustomerId,
        clearSelection: () => {
            selectedCustomerId = null;
            hideResults();
            setStatus('');
        },
    };
}
