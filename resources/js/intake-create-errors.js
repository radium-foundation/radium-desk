export const INTAKE_CREATE_GENERIC_ERROR = 'Unable to create service request. Please try again.';
export const INTAKE_CREATE_SESSION_ERROR = 'Your session has expired. Please refresh the page and try again.';
export const INTAKE_CREATE_NETWORK_ERROR = 'Unable to complete this request. Check your network and try again.';
export const INTAKE_CREATE_MISSING_ORDER_ERROR = 'Order ID is missing. Search for the order and try again.';

export const parseIntakeJsonResponse = async (response) => {
    const contentType = response.headers.get('content-type') ?? '';

    if (!contentType.includes('application/json')) {
        return null;
    }

    try {
        return await response.json();
    } catch {
        return null;
    }
};

export const intakeCreateValidationMessage = (
    data,
    response = null,
    fallback = INTAKE_CREATE_GENERIC_ERROR,
) => {
    const status = response?.status ?? 422;

    if (data?.errors && typeof data.errors === 'object') {
        const firstError = Object.values(data.errors)
            .flat()
            .find((value) => typeof value === 'string' && value !== '');

        if (firstError) {
            return firstError;
        }
    }

    if (status === 422 && typeof data?.message === 'string' && data.message !== '') {
        return data.message;
    }

    return fallback;
};

export const intakeCreateHttpErrorMessage = (response) => {
    const status = response?.status ?? 0;

    if (status === 419 || status === 401 || status === 403) {
        return INTAKE_CREATE_SESSION_ERROR;
    }

    return INTAKE_CREATE_GENERIC_ERROR;
};

export const intakeCreateNetworkErrorMessage = () => INTAKE_CREATE_NETWORK_ERROR;

export const resolveIntakeCreateErrorMessage = (response, data) => {
    const status = response?.status ?? 0;

    if (status === 419 || status === 401 || status === 403) {
        return INTAKE_CREATE_SESSION_ERROR;
    }

    if (status >= 500) {
        return INTAKE_CREATE_GENERIC_ERROR;
    }

    if (data !== null) {
        return intakeCreateValidationMessage(data, response);
    }

    return intakeCreateHttpErrorMessage(response);
};
