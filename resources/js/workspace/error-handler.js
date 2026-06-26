export class WorkspaceRequestError extends Error {
    constructor(type, status = null, message = 'Unable to complete this request.', data = null) {
        super(message);
        this.name = 'WorkspaceRequestError';
        this.type = type;
        this.status = status;
        this.data = data;
    }
}

const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

export const renderWorkspaceErrorHtml = (message) => `
<div class="p-4 text-danger small" data-workspace-error role="alert">${escapeHtml(message)}</div>`;

const validationMessage = (data) => {
    const errors = data?.errors;

    if (!errors || typeof errors !== 'object') {
        return data?.message ?? 'The submitted data was invalid.';
    }

    const firstField = Object.keys(errors)[0];
    const firstMessage = firstField ? errors[firstField]?.[0] : null;

    return firstMessage ?? data?.message ?? 'The submitted data was invalid.';
};

const statusMessage = (status, data) => {
    switch (status) {
        case 403:
            return data?.message ?? 'You do not have permission to perform this action.';
        case 404:
            return data?.message ?? 'The requested resource could not be found.';
        case 422:
            return validationMessage(data);
        case 500:
            return data?.message ?? 'Something went wrong on the server. Please try again.';
        default:
            return data?.message ?? 'Unable to complete this request.';
    }
};

export const isWorkspaceResponse = (data) => (
    Boolean(data)
    && typeof data === 'object'
    && Object.prototype.hasOwnProperty.call(data, 'success')
);

export const handleWorkspaceError = (error, response = null, data = null) => {
    if (isWorkspaceResponse(data)) {
        return data;
    }

    if (error instanceof WorkspaceRequestError) {
        return {
            success: false,
            message: error.message,
            toast: {
                show: true,
                message: error.message,
                variant: 'danger',
            },
            inline: {
                html: renderWorkspaceErrorHtml(error.message),
            },
        };
    }

    if (error?.name === 'AbortError') {
        const message = 'The request timed out. Please try again.';

        return {
            success: false,
            message,
            toast: {
                show: true,
                message,
                variant: 'danger',
            },
            inline: {
                html: renderWorkspaceErrorHtml(message),
            },
        };
    }

    if (error instanceof TypeError || error instanceof DOMException) {
        const message = 'Unable to connect. Check your network and try again.';

        return {
            success: false,
            message,
            toast: {
                show: true,
                message,
                variant: 'danger',
            },
            inline: {
                html: renderWorkspaceErrorHtml(message),
            },
        };
    }

    if (response && !response.ok) {
        const message = statusMessage(response.status, data);

        return {
            success: false,
            message,
            toast: {
                show: true,
                message,
                variant: 'danger',
            },
            inline: {
                html: renderWorkspaceErrorHtml(message),
            },
        };
    }

    const message = 'Unable to complete this request.';

    return {
        success: false,
        message,
        toast: {
            show: true,
            message,
            variant: 'danger',
        },
        inline: {
            html: renderWorkspaceErrorHtml(message),
        },
    };
};
