<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Link Incidents</h2>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Search and select incidents to attach. You can link up to
            <strong>{{ $remainingSlots }}</strong> more incident(s).
        </p>

        <form method="POST" action="{{ route('approvals.incidents.link', $approval) }}" id="link_incidents_form">
            @csrf

            <div class="mb-3">
                <label for="incident_search" class="form-label">Search Incidents</label>
                <div class="position-relative">
                    <input type="text"
                           id="incident_search"
                           class="form-control"
                           placeholder="Search by reference, order ID, or serial number..."
                           autocomplete="off"
                           data-lookup-url="{{ route('approvals.incidents.lookup', $approval) }}">
                    <div id="incident_search_results"
                         class="list-group position-absolute w-100 shadow-sm d-none"
                         style="z-index: 1050; max-height: 240px; overflow-y: auto;"></div>
                </div>
                <div class="form-text">Select incidents from the search results to add them below.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Selected Incidents</label>
                <div id="selected_incidents_list" class="list-group mb-2">
                    <div id="selected_incidents_empty" class="list-group-item text-muted small">
                        No incidents selected yet.
                    </div>
                </div>
                @error('incident_ids')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
                @error('incident_ids.*')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary" id="link_incidents_submit" disabled>
                <i class="bi bi-link-45deg me-1"></i> Link Selected Incidents
            </button>
        </form>
    </div>
</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('incident_search');
            const resultsBox = document.getElementById('incident_search_results');
            const selectedList = document.getElementById('selected_incidents_list');
            const emptyState = document.getElementById('selected_incidents_empty');
            const submitButton = document.getElementById('link_incidents_submit');
            const form = document.getElementById('link_incidents_form');
            const maxSelections = {{ (int) $remainingSlots }};

            if (!searchInput || !resultsBox || !selectedList || !form) {
                return;
            }

            const selectedIncidents = new Map();
            let debounceTimer = null;

            const updateSubmitState = () => {
                if (submitButton) {
                    submitButton.disabled = selectedIncidents.size === 0;
                }

                if (emptyState) {
                    emptyState.classList.toggle('d-none', selectedIncidents.size > 0);
                }
            };

            const syncHiddenInputs = () => {
                form.querySelectorAll('input[name="incident_ids[]"]').forEach((input) => input.remove());

                selectedIncidents.forEach((incident, id) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'incident_ids[]';
                    input.value = id;
                    form.appendChild(input);
                });
            };

            const renderSelectedIncident = (incident) => {
                const item = document.createElement('div');
                item.className = 'list-group-item d-flex justify-content-between align-items-start gap-2';
                item.dataset.incidentId = incident.id;
                item.innerHTML = `
                    <div>
                        <div class="fw-semibold">${incident.reference_no}</div>
                        <div class="small text-muted">${incident.order_id ?? '—'} · ${incident.title}</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" aria-label="Remove">
                        <i class="bi bi-x-lg"></i>
                    </button>
                `;

                item.querySelector('button').addEventListener('click', () => {
                    selectedIncidents.delete(String(incident.id));
                    item.remove();
                    syncHiddenInputs();
                    updateSubmitState();
                });

                selectedList.appendChild(item);
            };

            const addIncident = (incident) => {
                const id = String(incident.id);

                if (selectedIncidents.has(id)) {
                    return;
                }

                if (selectedIncidents.size >= maxSelections) {
                    window.alert(`You can only link ${maxSelections} more incident(s) to this approval number.`);
                    return;
                }

                selectedIncidents.set(id, incident);
                renderSelectedIncident(incident);
                syncHiddenInputs();
                updateSubmitState();
            };

            const renderResults = (incidents) => {
                resultsBox.innerHTML = '';

                if (!incidents.length) {
                    resultsBox.innerHTML = '<div class="list-group-item text-muted small">No incidents found.</div>';
                    resultsBox.classList.remove('d-none');
                    return;
                }

                incidents.forEach((incident) => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'list-group-item list-group-item-action';
                    item.innerHTML = `
                        <div class="fw-semibold">${incident.reference_no}</div>
                        <div class="small text-muted">${incident.order_id ?? '—'} · ${incident.title}</div>
                    `;
                    item.addEventListener('click', () => {
                        addIncident(incident);
                        resultsBox.classList.add('d-none');
                        searchInput.value = '';
                    });
                    resultsBox.appendChild(item);
                });

                resultsBox.classList.remove('d-none');
            };

            searchInput.addEventListener('input', () => {
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

            updateSubmitState();
        });
    </script>
@endpush
