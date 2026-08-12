import { describe, expect, it } from 'vitest';
import {
    INTAKE_CREATE_GENERIC_ERROR,
    INTAKE_CREATE_NETWORK_ERROR,
    INTAKE_CREATE_SESSION_ERROR,
    intakeCreateHttpErrorMessage,
    intakeCreateNetworkErrorMessage,
    intakeCreateValidationMessage,
    resolveIntakeCreateErrorMessage,
} from '../../resources/js/intake-create-errors';

const jsonResponse = (status, body) => ({
    ok: false,
    status,
    headers: {
        get: (name) => (name.toLowerCase() === 'content-type' ? 'application/json' : null),
    },
    json: async () => body,
});

describe('intake-create-errors', () => {
    it('returns the first validation error from JSON payloads', () => {
        expect(intakeCreateValidationMessage({
            errors: {
                legacy_order_id: ['This order already exists in Radium Desk.'],
            },
        })).toBe('This order already exists in Radium Desk.');
    });

    it('returns session-expired guidance for JSON HTTP 419', () => {
        expect(resolveIntakeCreateErrorMessage(
            jsonResponse(419, { message: 'CSRF token mismatch.' }),
            { message: 'CSRF token mismatch.' },
        )).toBe(INTAKE_CREATE_SESSION_ERROR);
    });

    it('returns session-expired guidance for JSON HTTP 401', () => {
        expect(resolveIntakeCreateErrorMessage(
            jsonResponse(401, { message: 'Unauthenticated.' }),
            { message: 'Unauthenticated.' },
        )).toBe(INTAKE_CREATE_SESSION_ERROR);
    });

    it('returns session-expired guidance for JSON HTTP 403', () => {
        expect(resolveIntakeCreateErrorMessage(
            jsonResponse(403, { message: 'This action is unauthorized.' }),
            { message: 'This action is unauthorized.' },
        )).toBe(INTAKE_CREATE_SESSION_ERROR);
    });

    it('returns session-expired guidance for non-json HTTP 419', () => {
        expect(intakeCreateHttpErrorMessage({ status: 419 })).toBe(INTAKE_CREATE_SESSION_ERROR);
    });

    it('returns a safe generic message for JSON HTTP 500', () => {
        expect(resolveIntakeCreateErrorMessage(
            jsonResponse(500, {
                message: 'SQLSTATE[HY000]: General error',
            }),
            { message: 'SQLSTATE[HY000]: General error' },
        )).toBe(INTAKE_CREATE_GENERIC_ERROR);
    });

    it('returns a safe generic message for non-json HTTP 500', () => {
        expect(resolveIntakeCreateErrorMessage({ status: 500 }, null)).toBe(
            INTAKE_CREATE_GENERIC_ERROR,
        );
    });

    it('returns useful validation/business messages for JSON HTTP 422', () => {
        const payload = {
            message: 'The given data was invalid.',
            errors: {
                legacy_order_id: ['This order already exists in Radium Desk.'],
            },
        };

        expect(resolveIntakeCreateErrorMessage(jsonResponse(422, payload), payload)).toBe(
            'This order already exists in Radium Desk.',
        );
    });

    it('returns a network failure message for fetch errors', () => {
        expect(intakeCreateNetworkErrorMessage()).toBe(INTAKE_CREATE_NETWORK_ERROR);
    });
});
