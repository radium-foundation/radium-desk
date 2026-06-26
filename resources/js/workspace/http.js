import { WorkspaceRequestError } from './error-handler';

export const DEFAULT_WORKSPACE_TIMEOUT_MS = 30000;

export const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

export const workspaceFetchHeaders = (accept = 'application/json') => ({
    Accept: accept,
    'X-CSRF-TOKEN': csrfToken(),
    'X-Requested-With': 'XMLHttpRequest',
});

export const workspaceFetch = async (url, options = {}) => {
    const {
        timeout = DEFAULT_WORKSPACE_TIMEOUT_MS,
        headers,
        ...fetchOptions
    } = options;

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);

    try {
        return await fetch(url, {
            ...fetchOptions,
            headers: headers ?? workspaceFetchHeaders(),
            signal: controller.signal,
        });
    } catch (error) {
        if (error?.name === 'AbortError') {
            throw new WorkspaceRequestError(
                'timeout',
                null,
                'The request timed out. Please try again.',
            );
        }

        throw new WorkspaceRequestError(
            'network',
            null,
            'Unable to connect. Check your network and try again.',
        );
    } finally {
        clearTimeout(timeoutId);
    }
};
