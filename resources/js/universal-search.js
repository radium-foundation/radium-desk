const SEARCH_ICON_HTML = '<i class="bi bi-search"></i>';
const SEARCH_LOADING_HTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
const MAX_RESULTS = 20;

const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const renderResultRow = (result) => {
    const cells = [
        ['Service Case', result.service_case],
        ['Order ID', result.order_id],
        ['Reference', result.reference_number],
        ['Customer', result.customer],
        ['Phone', result.phone],
        ['Assigned To', result.assigned_to],
        ['Status', result.status],
        ['Age', result.age],
    ];

    const meta = cells
        .map(([label, value]) => (
            `<span class="global-search-result__meta-item"><span class="global-search-result__meta-label">${escapeHtml(label)}:</span> ${escapeHtml(value)}</span>`
        ))
        .join('');

    return `
        <a href="${escapeHtml(result.url)}"
           class="global-search-result list-group-item list-group-item-action"
           data-global-search-result
           data-incident-id="${escapeHtml(result.incident_id)}">
            <div class="global-search-result__primary">${escapeHtml(result.service_case)} · ${escapeHtml(result.order_id)}</div>
            <div class="global-search-result__meta">${meta}</div>
        </a>
    `;
};

const renderResultsPanel = (results, matchCount) => {
    if (matchCount === 0) {
        return `
            <div class="global-search-results__empty" role="status">
                No service cases match this search.
            </div>
        `;
    }

    const cappedCount = Math.min(matchCount, MAX_RESULTS);
    const summary = matchCount > MAX_RESULTS
        ? `Showing ${MAX_RESULTS} of ${matchCount} matches`
        : `${matchCount} match${matchCount === 1 ? '' : 'es'}`;

    return `
        <div class="global-search-results__summary" role="status">${escapeHtml(summary)}</div>
        <div class="list-group list-group-flush global-search-results__list">
            ${results.slice(0, MAX_RESULTS).map(renderResultRow).join('')}
        </div>
    `;
};

export const initUniversalSearch = () => {
    const form = document.querySelector('[data-universal-search-form]');
    const globalInput = document.getElementById('global-search-input');
    const searchUrl = form?.dataset.searchUrl ?? '';
    const resultsPanel = document.getElementById('global-search-results');
    const searchControl = document.querySelector('[data-universal-search-control]');
    const searchIcon = document.querySelector('[data-universal-search-icon]');

    let searchRequestId = 0;
    let searchAbortController = null;

    const setSearchLoading = (loading) => {
        if (!searchIcon) {
            return;
        }

        searchControl?.toggleAttribute('aria-busy', loading);
        searchIcon.innerHTML = loading ? SEARCH_LOADING_HTML : SEARCH_ICON_HTML;
    };

    const hideResults = () => {
        if (!resultsPanel) {
            return;
        }

        resultsPanel.classList.add('d-none');
        resultsPanel.innerHTML = '';
        resultsPanel.removeAttribute('aria-live');
    };

    const showResults = (html) => {
        if (!resultsPanel) {
            return;
        }

        resultsPanel.innerHTML = html;
        resultsPanel.classList.remove('d-none');
        resultsPanel.setAttribute('aria-live', 'polite');
    };

    const runUniversalSearch = async (query) => {
        const trimmedQuery = query.trim();

        if (!searchUrl || trimmedQuery === '') {
            setSearchLoading(false);
            hideResults();

            return;
        }

        searchAbortController?.abort();
        searchAbortController = new AbortController();
        const requestId = ++searchRequestId;

        setSearchLoading(true);

        const params = new URLSearchParams({ q: trimmedQuery });

        try {
            const response = await fetch(`${searchUrl}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: searchAbortController.signal,
            });

            if (!response.ok || requestId !== searchRequestId) {
                return;
            }

            const data = await response.json();

            if (requestId !== searchRequestId) {
                return;
            }

            showResults(renderResultsPanel(data.results ?? [], data.match_count ?? 0));
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
        } finally {
            if (requestId === searchRequestId) {
                setSearchLoading(false);
            }
        }
    };

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        runUniversalSearch(globalInput?.value ?? '');
    });

    globalInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        runUniversalSearch(globalInput.value);
    });

    document.addEventListener('click', (event) => {
        if (!form?.contains(event.target) && !resultsPanel?.contains(event.target)) {
            hideResults();
        }
    });

    globalInput?.addEventListener('input', () => {
        if ((globalInput.value ?? '').trim() === '') {
            searchAbortController?.abort();
            searchRequestId += 1;
            setSearchLoading(false);
            hideResults();
        }
    });
};
