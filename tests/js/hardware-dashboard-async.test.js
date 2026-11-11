import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    buildHardwareRowsUrl,
    hardwareLastPage,
    parsePositiveInt,
    shouldAcceptRowsResponse,
} from '../../resources/js/hardware-dashboard-progressive';

const buildWorkspace = (overrides = {}) => {
    const element = document.createElement('div');
    element.dataset.hardwareRowsUrl = '/dashboard/hardware/rows';
    element.dataset.hardwareScope = 'active';
    element.dataset.hardwareFilter = 'needs_action';
    element.dataset.hardwarePage = '1';
    element.dataset.hardwareProgressive = '1';
    element.dataset.hardwareSearch = '';
    Object.assign(element.dataset, overrides);

    return element;
};

describe('hardware dashboard progressive helpers', () => {
    it('parses positive integers with fallback', () => {
        expect(parsePositiveInt('10', 40)).toBe(10);
        expect(parsePositiveInt('0', 40)).toBe(40);
        expect(parsePositiveInt('abc', 7)).toBe(7);
    });

    it('builds first-page progressive rows URL with ten-row semantics', () => {
        const workspace = buildWorkspace();

        const url = buildHardwareRowsUrl(workspace, { page: '1' });

        expect(url.searchParams.get('hw_progressive')).toBe('1');
        expect(url.searchParams.get('hw_page')).toBe('1');
        expect(url.searchParams.get('hw_filter')).toBe('needs_action');
    });

    it('includes search query and resets page param in URL', () => {
        const workspace = buildWorkspace({ hardwareSearch: 'RDE123', hardwarePage: '2' });

        const url = buildHardwareRowsUrl(workspace, { page: '1' });

        expect(url.searchParams.get('q')).toBe('RDE123');
        expect(url.searchParams.get('hw_page')).toBe('1');
    });

    it('calculates mixed progressive last pages', () => {
        expect(hardwareLastPage(45, 10, 40)).toBe(2);
        expect(hardwareLastPage(8, 10, 40)).toBe(1);
        expect(hardwareLastPage(55, 10, 40)).toBe(3);
    });

    it('rejects stale async responses by generation', () => {
        expect(shouldAcceptRowsResponse(2, 2)).toBe(true);
        expect(shouldAcceptRowsResponse(1, 2)).toBe(false);
    });
});

describe('hardware dashboard async search debounce contract', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('debounces search scheduling before firing load', () => {
        const calls = [];
        const debounceMs = 300;
        let timer = null;

        const schedule = (value) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                calls.push(value);
            }, debounceMs);
        };

        schedule('a');
        schedule('ab');
        vi.advanceTimersByTime(299);
        expect(calls).toEqual([]);
        vi.advanceTimersByTime(1);
        expect(calls).toEqual(['ab']);
    });
});

describe('hardware dashboard pagination transitions', () => {
    it('builds page-two URL while keeping progressive flag', () => {
        const workspace = buildWorkspace({ hardwarePage: '2' });
        const url = buildHardwareRowsUrl(workspace, { page: '2' });

        expect(url.searchParams.get('hw_page')).toBe('2');
        expect(url.searchParams.get('hw_progressive')).toBe('1');
    });
});
