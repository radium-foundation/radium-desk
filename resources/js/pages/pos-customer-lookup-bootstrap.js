import { initPosCustomerLookup } from './pos-customer-lookup.js';

const root = document.getElementById('pos-customer-lookup-root');

if (root) {
    const config = JSON.parse(root.dataset.config || '{}');
    const fieldIds = config.fieldIds || {};

    initPosCustomerLookup({
        searchUrl: config.searchUrl,
        showUrlTemplate: config.showUrlTemplate,
        lookupUrl: config.lookupUrl || null,
        fields: {
            phone: fieldIds.phone ? document.getElementById(fieldIds.phone) : null,
            name: fieldIds.name ? document.getElementById(fieldIds.name) : null,
            email: fieldIds.email ? document.getElementById(fieldIds.email) : null,
            gstin: fieldIds.gstin ? document.getElementById(fieldIds.gstin) : null,
            billing_address: fieldIds.billing_address ? document.getElementById(fieldIds.billing_address) : null,
            billing_city: fieldIds.billing_city ? document.getElementById(fieldIds.billing_city) : null,
            billing_state: fieldIds.billing_state ? document.getElementById(fieldIds.billing_state) : null,
            billing_pincode: fieldIds.billing_pincode ? document.getElementById(fieldIds.billing_pincode) : null,
            place_of_supply_state: fieldIds.place_of_supply_state ? document.getElementById(fieldIds.place_of_supply_state) : null,
        },
        resultsEl: config.resultsId ? document.getElementById(config.resultsId) : null,
        statusEl: config.statusId ? document.getElementById(config.statusId) : null,
        searchInputs: (config.searchInputIds || [])
            .map((id) => document.getElementById(id))
            .filter(Boolean),
        selectedMessage: config.selectedMessage || undefined,
        noResultsMessage: config.noResultsMessage || undefined,
    });
}
