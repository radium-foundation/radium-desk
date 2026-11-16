export const OEM_RESULT_OBSERVE_MAX_MS = 600_000;

/**
 * @param {Document} doc
 * @param {{ isReady: (doc: Document) => boolean, buildPayload: (doc: Document) => object | null, onEmit: (payload: object) => void, maxObserveMs?: number }} options
 */
export function observeOemResult(doc, options) {
    const maxMs = options.maxObserveMs ?? OEM_RESULT_OBSERVE_MAX_MS;
    let emitted = false;

    const tryEmit = () => {
        if (emitted || !options.isReady(doc)) {
            return;
        }

        const payload = options.buildPayload(doc);
        if (!payload) {
            return;
        }

        emitted = true;
        observer.disconnect();
        clearTimeout(timeoutId);
        options.onEmit(payload);
    };

    const observer = new MutationObserver(() => {
        tryEmit();
    });

    observer.observe(doc.body, { childList: true, subtree: true });
    const timeoutId = window.setTimeout(() => {
        observer.disconnect();
    }, maxMs);

    tryEmit();

    return () => {
        emitted = true;
        observer.disconnect();
        clearTimeout(timeoutId);
    };
}